<?php

namespace Webkul\Distribuidor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Core\Services\Distribuidores;
use Webkul\Distribuidor\Services\Reparto;
use Webkul\Lead\Services\CitySyncService;

/**
 * Le pone dueño a los clientes que ya existen.
 *
 * Desde que cada distribuidor ve sólo SUS clientes, un cliente sin dueño no lo ve
 * ningún distribuidor: ni su ficha, ni su conversación. Los clientes cargados antes
 * de este cambio no tienen dueño, así que hay que correr esto una vez después del
 * deploy (y se puede volver a correr: lo que ya tiene dueño correcto no se toca).
 *
 * Por cliente, en este orden:
 *   1. Ya es de un distribuidor de su ciudad: se queda con él.
 *   2. Sus pedidos están a nombre de un distribuidor de su ciudad: ese pasa a ser el
 *      dueño. Así cada distribuidor conserva los pedidos que ya tenía.
 *   3. Sin dueño y su ciudad tiene un solo distribuidor: ese.
 *   4. Si no, sus pedidos abiertos pasan al responsable (Huascar), que los reparte.
 *      Los clientes sin ciudad van siempre por acá: los ve sólo el responsable.
 *
 * Sin --aplicar sólo muestra lo que haría. Nada cambia hasta que se lo pide.
 */
class RepartirClientes extends Command
{
    protected $signature = 'distribuidor:repartir
        {--aplicar : Escribe los cambios. Sin esto, sólo muestra el plan.}
        {--ciudad= : Sólo esta ciudad (id de pipeline).}';

    protected $description = 'Asigna un distribuidor dueño a los clientes existentes (por defecto, sólo muestra el plan).';

    public function handle(Distribuidores $distribuidores, Reparto $reparto): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $soloCiudad = $this->option('ciudad') ? (int) $this->option('ciudad') : null;
        $responsable = $reparto->responsable();

        $this->line($aplicar ? '<comment>Aplicando cambios.</comment>' : '<info>Modo prueba: no se cambia nada. Usá --aplicar para escribir.</info>');

        if (! $responsable) {
            $this->warn('No hay responsable configurado (DISTRIBUIDOR_RESPONSABLE_EMAIL): los pedidos sin distribuidor quedan como están.');
        }

        $aDistribuidor = [];
        $alResponsable = [];
        $resumen = [];

        foreach ($this->clientes() as $personId) {
            $ciudad = $reparto->ciudadDelCliente($personId);

            if ($soloCiudad && $ciudad !== $soloCiudad) {
                continue;
            }

            $clave = $ciudad ?: 0;
            $resumen[$clave] ??= ['ya_tenia' => 0, 'por_pedidos' => 0, 'unico' => 0, 'al_responsable' => 0];
            $candidatos = $distribuidores->deCiudad($ciudad);
            $actual = $distribuidores->duenoDe($personId);

            if ($actual && in_array($actual, $candidatos, true)) {
                $resumen[$clave]['ya_tenia']++;

                continue;
            }

            if ($candidatos && ($porPedidos = $this->distribuidorDeSusPedidos($personId, (int) $ciudad, $candidatos))) {
                $aDistribuidor[] = [$personId, $porPedidos];
                $resumen[$clave]['por_pedidos']++;

                continue;
            }

            // Sin dueño en una ciudad con UN solo distribuidor: no hay a quién más
            // dárselo. Sin esto, el cliente que sólo escribió por WhatsApp (sin
            // pedido) no lo veía nadie en su ciudad.
            if (! $actual && count($candidatos) === 1) {
                $aDistribuidor[] = [$personId, $candidatos[0]];
                $resumen[$clave]['unico']++;

                continue;
            }

            if ($this->tienePedidosAbiertos($personId)) {
                $alResponsable[] = $personId;
                $resumen[$clave]['al_responsable']++;
            }
        }

        $this->mostrarResumen($resumen, $aDistribuidor, $distribuidores);

        if (! $aplicar || (! $aDistribuidor && ! $alResponsable)) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($aDistribuidor, $alResponsable, $reparto, $responsable) {
            foreach ($aDistribuidor as [$personId, $userId]) {
                $reparto->asignarCliente($personId, $userId);
            }

            if ($responsable && $alResponsable) {
                DB::table('leads')
                    ->whereIn('person_id', $alResponsable)
                    ->whereNotIn('lead_pipeline_stage_id', $this->etapasCerradas())
                    ->update(['user_id' => $responsable]);
            }
        });

        $this->info(count($aDistribuidor).' clientes asignados a su distribuidor; '
            .count($alResponsable).' con pedidos abiertos pasados al responsable.');

        return self::SUCCESS;
    }

    /**
     * Los clientes a considerar: los que tienen pedidos, conversación de WhatsApp o
     * ciudad en su ficha. Un contacto sin nada de eso no es de ninguna ciudad.
     *
     * @return iterable<int>
     */
    protected function clientes(): iterable
    {
        return DB::table('persons')
            ->where(function ($q) {
                $q->whereExists(fn ($s) => $s->from('leads')->whereColumn('leads.person_id', 'persons.id'))
                    ->orWhereExists(fn ($s) => $s->from('whatsapp_conversations')->whereColumn('whatsapp_conversations.person_id', 'persons.id'))
                    ->orWhereExists(fn ($s) => $s->from('attribute_values')
                        ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
                        ->where('attributes.code', CitySyncService::PERSON_CITY_ATTRIBUTE_CODE)
                        ->where('attribute_values.entity_type', 'persons')
                        ->whereColumn('attribute_values.entity_id', 'persons.id'));
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * El distribuidor de la ciudad que ya tiene el pedido más reciente del cliente.
     *
     * @param  array<int, int>  $candidatos
     */
    protected function distribuidorDeSusPedidos(int $personId, int $ciudad, array $candidatos): ?int
    {
        $userId = DB::table('leads')
            ->where('person_id', $personId)
            ->where('lead_pipeline_id', $ciudad)
            ->whereIn('user_id', $candidatos)
            ->orderByDesc('id')
            ->value('user_id');

        return $userId ? (int) $userId : null;
    }

    protected function tienePedidosAbiertos(int $personId): bool
    {
        return DB::table('leads')
            ->where('person_id', $personId)
            ->whereNotIn('lead_pipeline_stage_id', $this->etapasCerradas())
            ->exists();
    }

    /** @return array<int, int> */
    protected function etapasCerradas(): array
    {
        return DB::table('lead_pipeline_stages')
            ->whereIn('code', CitySyncService::CLOSED_STAGE_CODES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all() ?: [0];
    }

    protected function mostrarResumen(array $resumen, array $aDistribuidor, Distribuidores $distribuidores): void
    {
        $ciudades = DB::table('lead_pipelines')->pluck('name', 'id');

        $this->table(
            ['Ciudad', 'Ya tenían dueño', 'Por sus pedidos', 'Único distribuidor', 'Pedidos abiertos al responsable'],
            collect($resumen)->map(fn ($r, $ciudad) => [
                $ciudad ? ($ciudades[$ciudad] ?? "#{$ciudad}") : 'Sin ciudad',
                $r['ya_tenia'],
                $r['por_pedidos'],
                $r['unico'],
                $r['al_responsable'],
            ])->values()->all(),
        );

        $porDistribuidor = collect($aDistribuidor)->countBy(fn ($p) => $p[1]);

        if ($porDistribuidor->isNotEmpty()) {
            $this->table(
                ['Distribuidor', 'Clientes que conserva'],
                $porDistribuidor->map(fn ($n, $userId) => [$distribuidores->nombre($userId)." (#{$userId})", $n])->values()->all(),
            );
        }
    }
}
