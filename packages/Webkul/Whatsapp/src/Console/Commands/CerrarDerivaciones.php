<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Exceptions\HandoffConflictException;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Cierra las derivaciones abiertas hace más de N horas (Configuración →
 * WhatsApp → Derivaciones).
 *
 * Una derivación que nadie cierra deja al cliente sin agente y sin asesor:
 * escribe y no le contesta nadie. Se mide desde que se derivó, y lo corre el
 * scheduler cada 10 minutos.
 */
class CerrarDerivaciones extends Command
{
    protected $signature = 'whatsapp:cerrar-derivaciones
                            {--dry-run : Sólo mostrar, sin cerrar}';

    protected $description = 'Cierra las derivaciones a un asesor abiertas hace más de las horas configuradas';

    public function handle(Handoff $handoff): int
    {
        if (! core()->getConfigData('whatsapp.derivaciones.cierre_automatico')) {
            $this->info('El cierre automático de derivaciones está apagado.');

            return self::SUCCESS;
        }

        $horas = max(1, (int) core()->getConfigData('whatsapp.derivaciones.horas'));
        $devolver = (bool) core()->getConfigData('whatsapp.derivaciones.devolver_al_agente');

        $vencidas = ConversationProxy::modelClass()::query()
            ->whereIn('handoff_state', [Conversation::HANDOFF_REQUESTED, Conversation::HANDOFF_ASSIGNED])
            ->where('handoff_requested_at', '<=', now()->subHours($horas))
            ->get();

        $cerradas = 0;

        foreach ($vencidas as $conversacion) {
            if ($this->option('dry-run')) {
                $this->line("#{$conversacion->id} {$conversacion->wa_name} (derivada {$conversacion->handoff_requested_at})");

                continue;
            }

            try {
                $handoff->resolve($conversacion, null, $devolver);
                $cerradas++;
            } catch (HandoffConflictException) {
                // Un asesor la cerró entre la consulta y ahora: ya está como se quería.
            }
        }

        $this->info($this->option('dry-run')
            ? "Se cerrarían {$vencidas->count()} derivación(es) de más de {$horas} h."
            : "Cerradas {$cerradas} derivación(es) de más de {$horas} h.");

        return self::SUCCESS;
    }
}
