<?php

namespace Webkul\Whatsapp\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Whatsapp\Services\VideoDeBienvenida;

/**
 * Cuando el cliente escribe, le manda el video de Sensia si todavía no lo recibió.
 *
 * Va con el primer mensaje porque es cuando la ventana de 24 h se abre: el video sale
 * como mensaje común, sin depender de una plantilla aprobada. Las reglas (a qué
 * números, una sola vez) viven en VideoDeBienvenida.
 */
class EnviarVideoDeSaludo
{
    public function __construct(protected VideoDeBienvenida $video) {}

    public function handle($mensaje): void
    {
        if (! $mensaje instanceof Model || $mensaje->direction !== 'inbound' || ! $mensaje->conversation) {
            return;
        }

        // El mensaje del cliente ya está guardado: un video que no sale no puede voltearlo.
        try {
            $this->video->enviarSiFalta($mensaje->conversation);
        } catch (Throwable $e) {
            Log::warning('WhatsApp: no se pudo encolar el video de bienvenida', [
                'conversation_id' => $mensaje->conversation_id,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
