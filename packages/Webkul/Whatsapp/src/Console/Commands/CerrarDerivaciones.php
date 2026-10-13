<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Jobs\CerrarDerivacion;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Cierra las derivaciones abiertas que llevan `cierre_horas` sin mensajes y
 * agenda el cierre de las demás. Sirve para las derivaciones que ya estaban
 * abiertas antes del cierre automático (que se agenda al derivar).
 */
class CerrarDerivaciones extends Command
{
    protected $signature = 'whatsapp:cerrar-derivaciones {--dry-run : Solo mostrar qué se cerraría}';

    protected $description = 'Cierra las derivaciones sin mensajes en las últimas N horas (whatsapp.handoff.cierre_horas) y agenda el resto.';

    public function handle(Handoff $handoff): int
    {
        if (CerrarDerivacion::horas() <= 0) {
            $this->warn('El cierre automático está desactivado (WHATSAPP_HANDOFF_CIERRE_HORAS=0).');

            return self::SUCCESS;
        }

        $cerradas = 0;
        $agendadas = 0;

        $abiertas = ConversationProxy::modelClass()::query()
            ->whereIn('handoff_state', Conversation::HANDOFF_OPEN_STATES)
            ->whereNotNull('handoff_requested_at')
            ->get();

        foreach ($abiertas as $conversation) {
            $vence = CerrarDerivacion::vence($conversation);

            if (now()->gte($vence)) {
                $cerradas++;

                if (! $this->option('dry-run')) {
                    $handoff->resolve($conversation, null, true, 'automáticamente tras '.CerrarDerivacion::horas().' h sin mensajes');
                }
            } else {
                $agendadas++;

                if (! $this->option('dry-run')) {
                    CerrarDerivacion::agendar($conversation, $vence);
                }
            }
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')."Abiertas: {$abiertas->count()} · cerradas: {$cerradas} · con cierre agendado: {$agendadas}");

        return self::SUCCESS;
    }
}
