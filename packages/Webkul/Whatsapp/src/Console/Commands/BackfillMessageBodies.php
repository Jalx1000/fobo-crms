<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Rellena el texto de los mensajes que se guardaron sin él.
 *
 * Dos tandas, por dos motivos distintos:
 *
 *  - `interactive` de agosto: se ingirieron antes de que extractBody() supiera
 *    leer la etiqueta de una opción tocada. El payload SÍ la tiene; sólo hay
 *    que releerlo. Desde el 31/08 entran bien.
 *  - `reaction`: nunca se soportó, así que ninguna tiene texto.
 *
 * El payload crudo se guarda entero, así que esto no pide nada a Meta —
 * importante, porque las referencias de Meta caducan y estos mensajes son
 * viejos.
 */
class BackfillMessageBodies extends Command
{
    protected $signature = 'whatsapp:backfill-bodies {--dry-run : Mostrar qué cambiaría, sin escribir}';

    protected $description = 'Rellena el body de mensajes interactivos y reacciones guardados sin texto';

    public function handle(GatewayManager $gateways): int
    {
        $seco = (bool) $this->option('dry-run');

        $mensajes = MessageProxy::modelClass()::query()
            ->whereIn('type', ['interactive', 'reaction'])
            ->whereNull('body')
            ->get();

        if ($mensajes->isEmpty()) {
            $this->info('No hay mensajes sin texto para rellenar.');

            return self::SUCCESS;
        }

        $this->line("Mensajes sin texto: {$mensajes->count()}".($seco ? '  (simulación)' : ''));

        $rellenados = 0;
        $sinDato = 0;

        foreach ($mensajes as $mensaje) {
            $payload = (array) $mensaje->payload;
            $gateway = $gateways->for($mensaje->conversation);

            $texto = match ($mensaje->type) {
                'interactive' => $gateway->interactiveSelection($payload)['title'] ?? null,
                // Vacío = el cliente quitó la reacción; eso NO es texto y la fila
                // se deja como está, que es como se distingue en el hilo.
                'reaction'    => ($payload['reaction']['emoji'] ?? '') ?: null,
                default       => null,
            };

            if ($texto === null) {
                $sinDato++;

                continue;
            }

            $this->line(sprintf('  #%-6d %-12s %s', $mensaje->id, $mensaje->type, $texto));

            if (! $seco) {
                // Sin tocar updated_at: no es una edición del mensaje, es
                // completar un dato que se perdió al guardarlo.
                $mensaje->timestamps = false;
                $mensaje->forceFill(['body' => $texto])->save();
            }

            $rellenados++;
        }

        $this->newLine();
        $this->info("Rellenados: {$rellenados}".($sinDato ? "  ·  sin dato en el payload: {$sinDato}" : ''));

        return self::SUCCESS;
    }
}
