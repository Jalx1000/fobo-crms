<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Whatsapp\Services\SilenceTracker;

/**
 * Recordatorio a las 4 h de nuestro último mensaje y cierre cuando el cliente
 * no contesta: en "No atendido" al cerrarse la ventana, en el resto a las 72 h.
 *
 * Primero los seguimientos y después los cierres: un pedido que hoy recibe
 * el recordatorio no puede cerrarse en la misma corrida, porque el
 * recordatorio pasa a ser nuestro último mensaje y reinicia las 72 h.
 *
 * Con WHATSAPP_AUTO_CLOSE_SINCE vacío no hace nada.
 */
class FollowupAndClose extends Command
{
    protected $signature = 'whatsapp:seguimiento-y-cierre
        {--dry-run : Mostrar qué se mandaría y qué se cerraría, sin escribir}';

    protected $description = 'Recordatorio a las 4 h y cierre en "Cliente sin interés" (No atendido: 24 h; resto: 72 h) sin respuesta del cliente';

    public function handle(SilenceTracker $tracker): int
    {
        $since = $tracker->since();

        if (! $since) {
            $this->info('Seguimiento y cierre apagados (WHATSAPP_AUTO_CLOSE_SINCE vacío).');

            return self::SUCCESS;
        }

        $seco = (bool) $this->option('dry-run');

        if ($seco) {
            $this->line('SIMULACIÓN: no se manda ni se cierra nada. Cuentan los mensajes desde '.$since->format('d/m/Y H:i').'.');
        }

        $this->seguimientos($tracker, $seco);
        $this->cierres($tracker, $seco);

        return self::SUCCESS;
    }

    private function seguimientos(SilenceTracker $tracker, bool $seco): void
    {
        $due = $tracker->followupsDue();

        $this->newLine();
        $this->line('Seguimientos: '.$due->count());

        if ($due->isEmpty()) {
            return;
        }

        $filas = [];
        $enviados = 0;

        foreach ($due as $conv) {
            $filas[] = [$conv->id, '#'.$conv->lead_id, $this->contacto($conv), $conv->last_inbound_at?->format('d/m H:i')];

            if ($seco) {
                continue;
            }

            try {
                $tracker->sendFollowup($conv);
                $enviados++;
            } catch (Throwable $e) {
                $this->warn('  conversación #'.$conv->id.': '.$e->getMessage());
            }
        }

        $this->table(['Conv', 'Pedido', 'Contacto', 'Último del cliente'], $filas);

        if (! $seco) {
            $this->info($enviados.' seguimiento(s) en cola.');
        }
    }

    private function cierres(SilenceTracker $tracker, bool $seco): void
    {
        $tresDias = $tracker->closuresDue();

        // Un pedido que cumple las dos reglas se cierra una vez, por la de 3 días.
        $due = $tresDias->map(fn ($conv) => [$conv, SilenceTracker::MARKER_TITLE, '3 días'])
            ->concat($tracker->unattendedClosuresDue()
                ->whereNotIn('lead_id', $tresDias->pluck('lead_id'))
                ->map(fn ($conv) => [$conv, SilenceTracker::UNATTENDED_MARKER_TITLE, 'No atendido 24 h']));

        $this->newLine();
        $this->line('Cierres: '.$due->count());

        if ($due->isEmpty()) {
            return;
        }

        $filas = [];
        $cerrados = 0;

        foreach ($due as [$conv, $marca, $motivo]) {
            $ultimo = $tracker->lastEffectiveMessage($conv->id);

            $filas[] = [$conv->id, '#'.$conv->lead_id, $this->contacto($conv), $motivo, substr((string) $ultimo?->created_at, 5, 11)];

            if ($seco) {
                continue;
            }

            try {
                if ($tracker->close($conv, $marca)) {
                    $cerrados++;
                }
            } catch (Throwable $e) {
                $this->warn('  pedido #'.$conv->lead_id.': '.$e->getMessage());
            }
        }

        $this->table(['Conv', 'Pedido', 'Contacto', 'Motivo', 'Nuestro último mensaje'], $filas);

        if (! $seco) {
            $this->info($cerrados.' pedido(s) pasados a "Cliente sin interés".');
        }
    }

    private function contacto($conv): string
    {
        return mb_strimwidth((string) ($conv->wa_name ?: $conv->wa_phone), 0, 24, '…');
    }
}
