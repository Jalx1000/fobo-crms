<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Jobs\Recontactar;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Agenda el recontacto de las conversaciones con la ventana de 24 h abierta.
 * Las nuevas se agendan solas con cada mensaje del cliente; esto cubre las que
 * ya estaban abiertas cuando se activó el recontacto.
 */
class AgendarRecontactos extends Command
{
    protected $signature = 'whatsapp:agendar-recontactos';

    protected $description = 'Agenda el recontacto (antes del cierre de la ventana de 24 h) de las conversaciones abiertas.';

    public function handle(): int
    {
        if (! Recontactar::habilitado()) {
            $this->warn('El recontacto está desactivado (WHATSAPP_RECONTACTO=false).');

            return self::SUCCESS;
        }

        $agendadas = 0;

        ConversationProxy::modelClass()::query()
            ->where('last_inbound_at', '>', now()->subDay())
            ->each(function ($conversation) use (&$agendadas) {
                Recontactar::agendar($conversation);
                $agendadas++;
            });

        $this->info("Recontactos agendados: {$agendadas}");

        return self::SUCCESS;
    }
}
