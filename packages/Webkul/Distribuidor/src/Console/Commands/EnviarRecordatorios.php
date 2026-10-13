<?php

namespace Webkul\Distribuidor\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Distribuidor\Services\RecordatorioDeRecompra;
use Webkul\Distribuidor\Services\Recordatorios;

/**
 * Manda los recordatorios de recompra que vencieron.
 *
 * 🔴 Lo despierta el SCHEDULER (`php artisan schedule:run` cada minuto). Sin un
 * proceso que lo corra, todo el sistema de recordatorios es código muerto aunque esté
 * encendido en la config: no hay nada más que pueda disparar un envío a futuro.
 */
class EnviarRecordatorios extends Command
{
    protected $signature = 'distribuidor:recordatorios
                            {--limit= : Cuántos mandar como máximo en esta corrida}
                            {--dry-run : Mostrar a quién le tocaría, sin mandar nada}';

    protected $description = 'Pregunta por WhatsApp a los clientes que ya deberían estar por quedarse sin agua si quieren repetir el pedido';

    public function handle(Recordatorios $recordatorios, RecordatorioDeRecompra $envio): int
    {
        if (! $recordatorios->habilitado()) {
            $this->warn('Los recordatorios están apagados (distribuidor.recordatorios.enabled).');

            return self::SUCCESS;
        }

        $limite = (int) ($this->option('limit') ?: config('distribuidor.recordatorios.por_corrida', 100));

        $vencidos = $recordatorios->vencidos($limite);

        if ($vencidos->isEmpty()) {
            $this->info('No hay recordatorios vencidos.');

            return self::SUCCESS;
        }

        $seco = (bool) $this->option('dry-run');

        $this->info($vencidos->count().' recordatorio(s) vencido(s)'.($seco ? ' — simulacro, no se manda nada' : ''));

        $enviados = 0;

        foreach ($vencidos as $fila) {
            $nombre = optional($fila->person)->name ?: ('persona '.$fila->person_id);
            $intento = $fila->intentos + 1;

            if ($seco) {
                $this->line("  · {$nombre} — vencía {$fila->proximo_at}, sería el intento {$intento}");

                continue;
            }

            if ($envio->enviarA((int) $fila->person_id)) {
                $enviados++;
                $this->line("  ✓ {$nombre} — intento {$intento}");
            } else {
                // No es necesariamente un error: puede haberse dado de baja entre que
                // se armó la lista y llegamos acá.
                $this->line("  · {$nombre} — no se envió");
            }
        }

        if (! $seco) {
            $this->info("{$enviados} enviado(s).");
        }

        return self::SUCCESS;
    }
}
