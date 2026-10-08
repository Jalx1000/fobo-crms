<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Devuelve el agente a las conversaciones donde quedó apagado.
 *
 * Reemplaza a whatsapp:cerrar-derivaciones-inactivas, que sólo miraba las
 * derivaciones y contaba desde el último mensaje del cliente. El problema era
 * más grande: de 100 conversaciones con el bot apagado, 98 no tenían ninguna
 * derivación. Alguien tocó el interruptor del inbox y nadie se acordó de
 * volver a prenderlo; entre ellas, una clienta que había pedido una promo el
 * día anterior y nunca recibió respuesta.
 *
 * El plazo corre desde que se apagó (ai_disabled_at), no desde el último
 * mensaje: es un tope a cuánto puede estar callado el bot, no una medida de
 * inactividad.
 *
 * Reactivar CIERRA la derivación abierta si la hay. Sin eso el agente seguiría
 * mudo por handoffOpen() aunque el flag dijera que sí.
 */
class ReactivateAgent extends Command
{
    protected $signature = 'whatsapp:reactivar-agente
        {--horas= : Sobrescribe whatsapp.reactivate_after_hours}
        {--dry-run : Mostrar cuáles se reactivarían, sin escribir}';

    protected $description = 'Vuelve a activar el agente en las conversaciones que llevan demasiado tiempo apagadas';

    public function handle(Handoff $handoff): int
    {
        $seco = (bool) $this->option('dry-run');
        $horas = (int) ($this->option('horas') ?: config('whatsapp.reactivate_after_hours'));

        if ($horas <= 0) {
            $this->info('Reactivación automática desactivada (whatsapp.reactivate_after_hours = 0).');

            return self::SUCCESS;
        }

        $corte = now()->subHours($horas);

        $apagadas = ConversationProxy::modelClass()::query()
            ->where('ai_enabled', false)
            /*
             * Sin marca de apagado no se toca: son las que ya estaban apagadas
             * antes de que existiera la columna. Que las reactive alguien a
             * mano después de mirarlas, no un cron de madrugada.
             */
            ->whereNotNull('ai_disabled_at')
            ->where('ai_disabled_at', '<=', $corte)
            ->orderBy('ai_disabled_at')
            ->get();

        if ($apagadas->isEmpty()) {
            $this->info('Ninguna conversación lleva más de '.$horas.' h con el agente apagado.');

            return self::SUCCESS;
        }

        $this->line($apagadas->count().' conversación(es) con el agente apagado hace más de '
            .$horas.' h'.($seco ? '   — SIMULACIÓN, no se escribe nada' : ''));
        $this->newLine();

        $filas = [];
        $reactivadas = 0;

        foreach ($apagadas as $conv) {
            $desde = (int) $conv->ai_disabled_at->diffInHours(now());
            $motivo = $conv->handoffOpen() ? 'derivación '.$conv->handoff_state : 'apagado a mano';

            $filas[] = [
                $conv->id,
                mb_strimwidth((string) ($conv->wa_name ?? $conv->wa_phone), 0, 24, '…'),
                $motivo,
                $desde.' h',
            ];

            if ($seco) {
                continue;
            }

            if ($this->reactivar($handoff, $conv, $desde)) {
                $reactivadas++;
            }
        }

        $this->table(['#', 'Contacto', 'Por qué estaba apagado', 'Hace'], $filas);

        $this->newLine();
        $this->info($seco
            ? 'Simulación: se reactivarían '.count($filas).'. Corré sin --dry-run para aplicarlo.'
            : $reactivadas.' reactivada(s); el agente vuelve a atenderlas.');

        return self::SUCCESS;
    }

    private function reactivar(Handoff $handoff, Conversation $conv, int $horas): bool
    {
        try {
            if ($conv->handoffOpen()) {
                // resolve() ya vuelve a prender el agente y deja la auditoría.
                $handoff->resolve($conv, null, true, 'Cerrada automáticamente tras '.$horas.' h con el agente apagado.');

                return true;
            }

            $conv->forceFill(['ai_enabled' => true])->save();

            return true;
        } catch (Throwable $e) {
            $this->warn('  #'.$conv->id.' no se pudo reactivar: '.$e->getMessage());

            return false;
        }
    }
}
