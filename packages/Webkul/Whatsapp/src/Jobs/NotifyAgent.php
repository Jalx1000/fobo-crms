<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Repositories\HandoffRepository;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\PoliticaDeHandoff;

/**
 * Notifies the external AI agent that an inbound message arrived, so it can
 * reply through the documented API. Only fires while the agent is enabled for
 * the conversation — turning the agent off is what stops AI replies.
 */
class NotifyAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public int $messageId) {}

    public function handle(AgentPayload $payloads, HandoffRepository $handoffs, PoliticaDeHandoff $politica): void
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

        // Re-check at run time: the human may have taken over since enqueue.
        if (! $message->conversation->aiEffective()) {
            Log::info('WhatsApp: evento descartado, la IA está apagada en la conversación.', [
                'message_id'      => $this->messageId,
                'conversation_id' => $message->conversation->id,
            ]);

            return;
        }

        /*
        | Derivada a un asesor: el agente tiene que callarse, así que ni siquiera
        | se entera de que llegó el mensaje.
        |
        | Salvo que nadie la haya tomado. Un handoff vigente y sin dueño dejaba
        | al cliente sin respuesta para siempre: el agente no recibía el evento,
        | y como el cierre solo ocurre por API, nadie pedía el cierre tampoco.
        | Pasado el plazo se devuelve la conversación y el evento sigue su curso.
        */
        if ($handoff = $handoffs->abiertoDe($message->conversation->id)) {
            if (! $politica->debeDevolverAlAgente($handoff)) {
                Log::info('WhatsApp: evento descartado, la conversación está derivada a un asesor.', [
                    'message_id'      => $this->messageId,
                    'conversation_id' => $message->conversation->id,
                    'handoff_id'      => $handoff->id,
                    'handoff_state'   => $handoff->state,
                ]);

                return;
            }

            $handoffs->cerrar($message->conversation->id);

            Log::warning('WhatsApp: handoff sin tomar devuelto al agente por vencimiento.', [
                'conversation_id' => $message->conversation->id,
                'handoff_id'      => $handoff->id,
                'opened_at'       => optional($handoff->opened_at)->toDateTimeString(),
            ]);
        }

        $response = Http::withToken((string) config('whatsapp.ai.token'))
            ->acceptJson()
            ->post($url, $payloads->event($message));

        if ($response->successful()) {
            return;
        }

        /*
        | El agente distingue dos familias de error y nosotros tenemos que
        | respetarlas:
        |
        |  - 4xx (401 token malo, 422 payload irreparable): reintentar no cambia
        |    nada. Tres reintentos idénticos solo agregan ruido y retrasan el
        |    resto de la cola. Se descarta y se deja registro.
        |  - 5xx: fallo transitorio del servicio del agente. Ahí sí, backoff.
        */
        if ($response->clientError()) {
            Log::warning('WhatsApp: el agente rechazó el evento y no se reintenta.', [
                'status'     => $response->status(),
                'message_id' => $this->messageId,
                'body'       => Str::limit($response->body(), 500),
            ]);

            return;
        }

        $response->throw();
    }
}
