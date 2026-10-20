<?php

namespace Webkul\Distribuidor\Listeners;

use Illuminate\Support\Facades\Log;
use Webkul\Distribuidor\Services\PreguntaDeCiudad;

/**
 * El cliente contestó la pregunta de la ciudad.
 *
 * Escucha cada mensaje entrante de WhatsApp; PreguntaDeCiudad decide si es la
 * respuesta (sólo si a esa conversación se le preguntó) y, si lo es, guarda la
 * ciudad y le asigna distribuidor.
 */
class CiudadRespondida
{
    public function __construct(
        protected PreguntaDeCiudad $pregunta,
    ) {}

    public function handle($message): void
    {
        try {
            $this->pregunta->registrarRespuesta($message);
        } catch (\Throwable $e) {
            // Guardar el mensaje del cliente es lo importante: esto es un extra y no
            // puede hacer que se pierda.
            Log::warning('No se pudo registrar la ciudad respondida', [
                'message_id' => $message->id ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
