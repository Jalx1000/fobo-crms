<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\CityRouter;
use Webkul\Whatsapp\Services\LeadOpener;

/**
 * Pone al día las conversaciones que quedaron sin pedido.
 *
 * Son dos casos muy distintos, y mezclarlos sería un error caro:
 *
 *  - ENLAZAR: el contacto YA tiene pedidos, la conversación simplemente no
 *    apunta a ninguno. No se crea nada; sólo se enlaza el más reciente. Cero
 *    impacto en las métricas.
 *  - ABRIR: el contacto no tiene ningún pedido. Acá sí se crea, en su ciudad y
 *    a nombre de su encargado.
 *
 * Los pedidos nuevos SÍ mueven los números del tablero, así que conviene correr
 * primero con --dry-run y mirar el reparto por ciudad.
 */
class BackfillConversationLeads extends Command
{
    protected $signature = 'whatsapp:backfill-leads
        {--dry-run : Mostrar qué haría, sin escribir}
        {--solo-enlazar : Enlazar los que ya tienen pedido, sin abrir ninguno nuevo}
        {--dias= : Limitar a conversaciones con mensajes en los últimos N días}';

    protected $description = 'Enlaza o abre el pedido de las conversaciones de WhatsApp que no tienen ninguno';

    public function handle(LeadOpener $leads, CityRouter $cities): int
    {
        $seco = (bool) $this->option('dry-run');
        $soloEnlazar = (bool) $this->option('solo-enlazar');
        $dias = $this->option('dias');

        $query = ConversationProxy::modelClass()::query()
            ->whereNull('lead_id')
            ->whereNotNull('person_id')
            ->with('person');

        if ($dias) {
            $query->where('last_message_at', '>=', now()->subDays((int) $dias));
        }

        $conversaciones = $query->get();

        if ($conversaciones->isEmpty()) {
            $this->info('No hay conversaciones sin pedido.');

            return self::SUCCESS;
        }

        $this->line('Conversaciones sin pedido: '.$conversaciones->count().($seco ? '   (simulación)' : ''));
        $this->newLine();

        $enlazados = 0;
        $abiertos = 0;
        $fallidos = 0;
        $porCiudad = [];

        foreach ($conversaciones as $conversacion) {
            $person = $conversacion->person;

            if (! $person) {
                continue;
            }

            if ($existente = $person->leads()->latest()->first()) {
                $this->line(sprintf('  enlazar  conv #%-5d -> pedido #%d', $conversacion->id, $existente->id));

                if (! $seco) {
                    $conversacion->forceFill(['lead_id' => $existente->id])->save();
                }

                $enlazados++;

                continue;
            }

            if ($soloEnlazar) {
                continue;
            }

            $pipeline = $leads->pipelineFor($person);

            /*
             * A quién le va a quedar, resuelto con EL MISMO método que usa la
             * creación real (ownerFor), no con advisorFor a secas: éste último
             * devuelve null para "Sin ciudad" y el dry-run mostraba
             * "(SIN ASESOR)" para pedidos que en realidad sí tienen dueño.
             */
            $asesorId = $pipeline ? $leads->ownerFor($pipeline, $person) : null;
            $asesor = $asesorId
                ? optional(UserProxy::modelClass()::find($asesorId))->name
                : '(SIN ASESOR)';

            $this->line(sprintf(
                '  ABRIR    conv #%-5d %-24s -> %-12s / %s',
                $conversacion->id,
                mb_substr((string) $person->name, 0, 24),
                $pipeline?->name ?? '(sin pipeline)',
                $asesor
            ));

            $clave = ($pipeline?->name ?? '(sin pipeline)').'  /  '.$asesor;
            $porCiudad[$clave] = ($porCiudad[$clave] ?? 0) + 1;

            if ($seco) {
                $abiertos++;

                continue;
            }

            $lead = $leads->open($person);

            if (! $lead) {
                $fallidos++;

                continue;
            }

            $conversacion->forceFill(['lead_id' => $lead->id])->save();
            $abiertos++;
        }

        $this->newLine();
        $this->info("Enlazados: {$enlazados}   ·   Pedidos abiertos: {$abiertos}".($fallidos ? "   ·   Fallidos: {$fallidos}" : ''));

        if ($porCiudad) {
            $this->newLine();
            $this->line('Pedidos nuevos por ciudad y asesor:');

            arsort($porCiudad);

            foreach ($porCiudad as $ciudad => $n) {
                $this->line(sprintf('   %-38s %d', $ciudad, $n));
            }
        }

        return self::SUCCESS;
    }
}
