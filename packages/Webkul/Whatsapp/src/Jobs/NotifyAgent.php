<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * Notifies the external AI agent of a message in the conversation.
 *
 * Con el agente prendido, un mensaje del cliente es su turno (`responder: true`)
 * y contesta por la API. Con el agente apagado, o un mensaje del asesor, le llega
 * con `responder: false`: solo para que tenga el contexto. Apagarlo es lo que
 * frena las respuestas, y el CRM rechaza (409) las que intente mandar igual.
 */
class NotifyAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public int $messageId) {}

    public function handle(AgentPayload $payloads): void
    {
        $url = (string) config('whatsapp.ai.webhook_url');

        if ($url === '') {
            Log::warning('WhatsApp: AI agent webhook URL not configured; event dropped.');

            return;
        }

        $message = MessageProxy::modelClass()::with('conversation')->find($this->messageId);

        if (! $message || ! $message->conversation) {
            return;
        }

        /*
         * Re-check at run time: the human may have taken over since enqueue. Con
         * el agente apagado se le avisa igual (responder: false) si así está
         * configurado, para que no pierda el hilo de la conversación.
         */
        if (! $message->conversation->aiEffective() && ! config('whatsapp.ai.context_when_off')) {
            return;
        }

        try {
            $respuesta = Http::withToken((string) config('whatsapp.ai.token'))
                ->acceptJson()
                ->post($url, $payloads->event($message));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Sin conexión: el turno del cliente se reintenta; un aviso de contexto, no.
            if ($message->direction === 'inbound' && $message->conversation->aiEffective()) {
                throw $e;
            }

            Log::warning('WhatsApp: no se pudo avisar al agente (contexto)', ['message_id' => $message->id, 'error' => $e->getMessage()]);

            return;
        }

        /*
         * Un rechazo del agente se registra con su status y su cuerpo ANTES de
         * reventar.
         *
         * Sin esto el fallo era completamente invisible, y costó una sesión de
         * diagnóstico: con `QUEUE_CONNECTION=sync` el job corre dentro del request
         * del webhook, así que la excepción de `throw()` subía por ahí y el webhook
         * igual contestaba 200 a Meta. Resultado: el mensaje se guardaba, Meta
         * quedaba conforme, el agente nunca se enteraba y no quedaba ni una línea
         * en el log. Todo parecía andar y el cliente no recibía respuesta.
         *
         * El caso real fue un 401: el token del CRM estaba copiado de otro tenant.
         * Con el status y el cuerpo a la vista, eso se diagnostica en diez segundos.
         */
        if ($respuesta->failed()) {
            Log::error('WhatsApp: el agente rechazó el evento', [
                'conversation_id' => $message->conversation_id,
                'message_id'      => $message->id,
                'status'          => $respuesta->status(),
                // Acotado: el cuerpo de un error puede ser una página HTML entera.
                'respuesta'       => mb_substr((string) $respuesta->body(), 0, 300),
                'url'             => $url,
            ]);
        }

        /*
         * Un aviso de contexto (agente apagado o mensaje del asesor) que falla no
         * se reintenta ni revienta: con la cola en `sync` correría dentro del
         * envío del asesor y le haría fallar su mensaje por un problema del agente.
         */
        if ($respuesta->failed() && ! ($message->direction === 'inbound' && $message->conversation->aiEffective())) {
            return;
        }

        $respuesta->throw();
    }
}
