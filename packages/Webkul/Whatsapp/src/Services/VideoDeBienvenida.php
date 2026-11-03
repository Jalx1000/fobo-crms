<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Core\Services\PhoneNumber;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * El video de Sensia que acompaña al saludo.
 *
 * Sale por dos caminos, según quién habla primero:
 *
 *   - El distribuidor carga al cliente en el Punto: no hay ventana de 24 h, así que el
 *     video sólo puede ir como encabezado de una plantilla aprobada (Bienvenida).
 *   - El cliente escribe primero y el agente le contesta con el saludo («Soy Sofía, tu
 *     asistente»): la ventana está abierta y el video va como mensaje común, justo
 *     antes del saludo (AgentController::send → enviarConSaludo).
 *
 * Lo dispara el saludo y no "ser cliente nuevo": al primer mensaje el CRM ya le abre a
 * la persona un pedido vacío (LeadOpener), así que "sin lead previo" no distingue a
 * nadie. Conversación nueva o vieja da igual; lo que cuenta es que Sofía salude.
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
     * Si el texto del agente es el saludo de Sofía. Se compara sin tildes ni mayúsculas
     * porque lo escribe un modelo: "Soy Sofia, tu asistente" también cuenta.
     */
    public function esSaludo(?string $texto): bool
    {
        $frase = $this->normalizar((string) config('whatsapp.bienvenida.video_saludo'));

        return $frase !== '' && str_contains($this->normalizar((string) $texto), $frase);
    }

    /**
     * Lo llama el agente antes de guardar su respuesta: si es el saludo, el video sale
     * primero (se encola antes, y la cola de WhatsApp despacha en orden).
     */
    public function enviarConSaludo(object $conversacion, ?string $texto): bool
    {
        return $this->esSaludo($texto) && $this->enviarSiFalta($conversacion);
    }

    /**
     * Manda el video como mensaje común si no salió en las últimas 24 h.
     *
     * Sólo con la ventana abierta: quien llama es la respuesta del agente a un mensaje
     * del cliente, que es justamente lo que la abre.
     */
    public function enviarSiFalta(object $conversacion): bool
    {
        if (! $this->url()
            || ! $this->aplicaA($conversacion->wa_phone)
            || $this->yaLoRecibio($conversacion->id)) {
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
     * Si ya le llegó en las últimas 24 h, por cualquiera de los dos caminos. Así el
     * video acompaña cada saludo (también en una conversación vieja que vuelve) pero no
     * se repite si el agente saluda dos veces en la misma charla. Un fallo cuenta como
     * intentado: reintentarlo en cada saludo sería mandárselo de golpe cuando se arregle.
     */
    protected function yaLoRecibio(int $conversacionId): bool
    {
        return MessageProxy::modelClass()::query()
            ->where('conversation_id', $conversacionId)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subDay())
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('type', 'video')->where('media_path', $this->url()))
                ->orWhere(fn ($q) => $q->where('type', 'template')
                    ->where('payload->template', (string) config('whatsapp.bienvenida.video_template'))))
            ->exists();
    }

    protected function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($texto))));
    }
}
