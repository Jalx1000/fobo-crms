<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Core\Services\PhoneNumber;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * El video de Sensia que acompaña al saludo, una sola vez por conversación.
 *
 * Sale por dos caminos, según quién habla primero:
 *
 *   - El distribuidor carga al cliente en el Punto: no hay ventana de 24 h, así que el
 *     video sólo puede ir como encabezado de una plantilla aprobada (Bienvenida).
 *   - El cliente escribe primero: la ventana está abierta y el video va como un
 *     mensaje común, sin plantilla (EnviarVideoDeSaludo).
 *
 * Acotado a una lista de números (WHATSAPP_BIENVENIDA_VIDEO_NUMEROS) para probarlo con
 * los del equipo antes de mandárselo a todos; `*` lo abre a todos.
 */
class VideoDeBienvenida
{
    public function aplicaA(?string $telefono): bool
    {
        $numeros = array_filter(array_map(
            'trim',
            preg_split('/[\s,;]+/', (string) config('whatsapp.bienvenida.video_numeros'))
        ));

        if (in_array('*', $numeros, true)) {
            return true;
        }

        foreach ($numeros as $numero) {
            if (PhoneNumber::same($telefono, $numero)) {
                return true;
            }
        }

        return false;
    }

    public function url(): string
    {
        return trim((string) config('whatsapp.bienvenida.video'));
    }

    /**
     * Manda el video como mensaje común si la conversación todavía no lo recibió.
     *
     * Sólo con la ventana abierta: quien llama es el aviso de un mensaje entrante,
     * que es justamente lo que la abre.
     */
    public function enviarSiFalta(object $conversacion): bool
    {
        if (! $this->url() || ! $this->aplicaA($conversacion->wa_phone) || $this->yaLoRecibio($conversacion->id)) {
            return false;
        }

        $mensaje = DB::transaction(function () use ($conversacion) {
            $mensaje = MessageProxy::modelClass()::create([
                'conversation_id' => $conversacion->id,
                'direction'       => 'outbound',
                'type'            => 'video',
                'media_path'      => $this->url(),
                'media_mime'      => 'video/mp4',
                'media_name'      => basename($this->url()),
                'status'          => 'queued',
                'sender'          => 'sistema',
                'payload'         => ['media' => [
                    'kind'     => 'video',
                    'url'      => $this->url(),
                    'filename' => basename($this->url()),
                    'mime'     => 'video/mp4',
                ]],
            ]);

            $conversacion->forceFill(['last_message_at' => now()])->save();

            return $mensaje;
        });

        SendMessage::dispatch($mensaje->id)->onQueue(config('whatsapp.queue'));

        return true;
    }

    /**
     * Si ya le llegó, por cualquiera de los dos caminos. Un fallo cuenta como
     * intentado: reintentarlo en cada mensaje del cliente sería mandárselo de golpe
     * cuando se arregle.
     */
    protected function yaLoRecibio(int $conversacionId): bool
    {
        return MessageProxy::modelClass()::query()
            ->where('conversation_id', $conversacionId)
            ->where('direction', 'outbound')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('type', 'video')->where('media_path', $this->url()))
                ->orWhere(fn ($q) => $q->where('type', 'template')
                    ->where('payload->template', (string) config('whatsapp.bienvenida.video_template'))))
            ->exists();
    }
}
