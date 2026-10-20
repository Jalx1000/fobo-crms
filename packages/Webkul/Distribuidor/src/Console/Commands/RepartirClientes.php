<?php

namespace Webkul\Distribuidor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Core\Services\Distribuidores;
use Webkul\Distribuidor\Services\Reparto;

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
 *   2. Sus pedidos ya están a nombre de un distribuidor de su ciudad: ese pasa a ser
 *      el dueño. Respeta el trabajo en curso en vez de mover clientes que alguien ya
 *      está atendiendo.
 *   3. Si no, el turno de su ciudad (round robin).
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

        $this->line($aplicar ? '<comment>Aplicando cambios.</comment>' : '<info>Modo prueba: no se cambia nada. Usá --aplicar para escribir.</info>');

        $plan = [];
        $resumen = [];
        $turnos = [];

        foreach ($this->clientes() as $personId) {
            $ciudad = $reparto->ciudadDelCliente($personId);

            if ($soloCiudad && $ciudad !== $soloCiudad) {
                continue;
            }

            $candidatos = $distribuidores->deCiudad($ciudad);
            $clave = $ciudad ?: 0;
            $resumen[$clave] ??= ['ya_tenia' => 0, 'por_pedidos' => 0, 'por_turno' => 0, 'sin_distribuidor' => 0];

            if (! $candidatos) {
                $resumen[$clave]['sin_distribuidor']++;

                continue;
            }

            $actual = $distribuidores->duenoDe($personId);

            if ($actual && in_array($actual, $candidatos, true)) {
                $resumen[$clave]['ya_tenia']++;

                continue;
            }

            if ($porPedidos = $this->distribuidorDeSusPedidos($personId, $ciudad, $candidatos)) {
                $plan[] = [$personId, $ciudad, $porPedidos, 'sus pedidos'];
                $resumen[$clave]['por_pedidos']++;

                continue;
            }

            // El turno se simula en memoria para que el modo prueba muestre el reparto
            // real sin marcar a nadie como "ya recibió".
            $turnos[$ciudad] ??= $this->ordenDelTurno($ciudad, $candidatos);
            $siguiente = array_shift($turnos[$ciudad]);
            $turnos[$ciudad][] = $siguiente;

            $plan[] = [$personId, $ciudad, $siguiente, 'turno'];
            $resumen[$clave]['por_turno']++;
        }

        $this->mostrarResumen($resumen, $plan, $distribuidores);

        if (! $aplicar || ! $plan) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $reparto) {
            foreach ($plan as [$personId, $ciudad, $userId]) {
                $reparto->asignarCliente($personId, $userId);

                // El turno avanza con cada asignación, para que lo próximo que entre
                // siga el orden donde lo dejó este reparto.
                DB::table('distribuidor_zonas')
                    ->where('lead_pipeline_id', $ciudad)
                    ->where('user_id', $userId)
                    ->update(['ultima_asignacion_at' => now()->format('Y-m-d H:i:s.u')]);
            }
        });

        $this->info(count($plan).' clientes asignados.');

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
                        ->where('attributes.code', 'cliente_ciudad')
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

    /**
     * Los distribuidores de la ciudad en el orden en que les toca: primero el que hace
     * más tiempo que no recibe un cliente. El mismo criterio que Reparto::siguiente().
     *
     * @param  array<int, int>  $candidatos
     * @return array<int, int>
     */
    protected function ordenDelTurno(int $ciudad, array $candidatos): array
    {
        return DB::table('distribuidor_zonas')
            ->where('lead_pipeline_id', $ciudad)
            ->whereIn('user_id', $candidatos)
            ->orderBy('ultima_asignacion_at')
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function mostrarResumen(array $resumen, array $plan, Distribuidores $distribuidores): void
    {
        $ciudades = DB::table('lead_pipelines')->pluck('name', 'id');

        $this->table(
            ['Ciudad', 'Ya tenían dueño', 'Por sus pedidos', 'Por turno', 'Sin distribuidor en la ciudad'],
            collect($resumen)->map(fn ($r, $ciudad) => [
                $ciudad ? ($ciudades[$ciudad] ?? "#{$ciudad}") : 'Sin ciudad',
                $r['ya_tenia'],
                $r['por_pedidos'],
                $r['por_turno'],
                $r['sin_distribuidor'],
            ])->values()->all(),
        );

        $porDistribuidor = collect($plan)->countBy(fn ($p) => $p[2]);

        if ($porDistribuidor->isNotEmpty()) {
            $this->table(
                ['Distribuidor', 'Clientes que recibe'],
                $porDistribuidor->map(fn ($n, $userId) => [$distribuidores->nombre($userId)." (#{$userId})", $n])->values()->all(),
            );
        }
    }
}
