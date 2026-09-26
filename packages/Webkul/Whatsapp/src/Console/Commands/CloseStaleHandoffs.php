<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Devuelve al bot las derivaciones que el cliente nunca contestó.
 *
 * Una derivación abierta silencia al agente (Conversation::aiEffective), así
 * que una que nadie cierra deja al cliente sin nadie del otro lado: el asesor ya
 * no la mira y el bot no puede hablar. Al medirlo, 10 de 15 derivaciones
 * abiertas ya habían pasado las 24 h; la más vieja llevaba 288 horas.
 *
 * El corte se cuenta desde el ÚLTIMO MENSAJE DEL CLIENTE (last_inbound_at), no
 * desde que se pidió la derivación: si el cliente sigue escribiendo, la
 * conversación está viva aunque el pedido de derivación sea de hace días.
 */
class CloseStaleHandoffs extends Command
{
    protected $signature = 'whatsapp:cerrar-derivaciones-inactivas
        {--horas=24 : Horas sin mensaje del cliente antes de devolverla al bot}
        {--dry-run : Mostrar cuáles se cerrarían, sin escribir}';

    protected $description = 'Cierra las derivaciones sin respuesta del cliente y devuelve la conversación al agente IA';

    public function handle(Handoff $handoff): int
    {
        $seco = (bool) $this->option('dry-run');
        $horas = max(1, (int) $this->option('horas'));
        $corte = now()->subHours($horas);

        $vencidas = ConversationProxy::modelClass()::query()
            ->whereIn('handoff_state', [Conversation::HANDOFF_REQUESTED, Conversation::HANDOFF_ASSIGNED])
            /*
             * Una conversación sin last_inbound_at nunca tuvo mensaje entrante:
             * no hay desde cuándo contar, así que no se toca.
             */
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', $corte)
            ->orderBy('last_inbound_at')
            ->get();

        if ($vencidas->isEmpty()) {
            $this->info('Ninguna derivación pasó las '.$horas.' h sin respuesta.');

            return self::SUCCESS;
        }

        $this->line($vencidas->count().' derivación(es) sin mensaje del cliente desde hace más de '
            .$horas.' h'.($seco ? '   — SIMULACIÓN, no se escribe nada' : ''));
        $this->newLine();

        $filas = [];
        $cerradas = 0;

        foreach ($vencidas as $conv) {
            $inactiva = (int) $conv->last_inbound_at->diffInHours(now());

            $filas[] = [
                $conv->id,
                mb_strimwidth((string) ($conv->wa_name ?? $conv->wa_phone), 0, 24, '…'),
                $conv->handoff_state,
                $inactiva.' h',
            ];

            if ($seco) {
                continue;
            }

            try {
                $handoff->resolve($conv, null, true, 'Cerrada automáticamente tras '.$inactiva.' h sin respuesta del cliente.');
                $cerradas++;
            } catch (\Throwable $e) {
                $this->warn('  #'.$conv->id.' no se pudo cerrar: '.$e->getMessage());
            }
        }

        $this->table(['#', 'Contacto', 'Estado', 'Inactiva'], $filas);

        $this->newLine();
        $this->info($seco
            ? 'Simulación: se cerrarían '.count($filas).'. Corré sin --dry-run para aplicarlo.'
            : $cerradas.' cerrada(s); la conversación vuelve al agente IA.');

        return self::SUCCESS;
    }
}
