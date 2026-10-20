<?php

namespace Webkul\Distribuidor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Webkul\Distribuidor\Services\PreguntaDeCiudad;

/**
 * Le pregunta la ciudad a los clientes de WhatsApp que todavía no la dijeron.
 *
 * Corre cada 10 minutos desde el scheduler. Sin ciudad no hay a qué distribuidor
 * asignar al cliente, y pasadas 24 h desde su último mensaje ya no se le puede
 * escribir sin una plantilla aprobada: por eso se pregunta adentro de la ventana.
 */
class PreguntarCiudad extends Command
{
    protected $signature = 'distribuidor:preguntar-ciudad';

    protected $description = 'Pregunta la ciudad por WhatsApp a los clientes que no la tienen, antes de que cierre la ventana de 24 h.';

    public function handle(PreguntaDeCiudad $pregunta): int
    {
        if (! config('distribuidor.pregunta_ciudad.enabled')) {
            return self::SUCCESS;
        }

        $enviadas = 0;

        foreach ($pregunta->pendientes() as $conversationId) {
            try {
                $enviadas += $pregunta->preguntar($conversationId) ? 1 : 0;
            } catch (\Throwable $e) {
                // Una conversación que falla no frena a las demás.
                Log::warning('No se pudo preguntar la ciudad', [
                    'conversation_id' => $conversationId,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        if ($enviadas) {
            $this->info("Ciudad preguntada a {$enviadas} clientes.");
        }

        return self::SUCCESS;
    }
}
