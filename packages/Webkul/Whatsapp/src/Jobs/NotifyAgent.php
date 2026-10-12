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
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\AlertaAgente;

/**
 * Notifies the external AI agent that an inbound message arrived, so it can
 * reply through the documented API. Only fires while the agent is enabled for
 * the conversation — turning the agent off is what stops AI replies.
 */
class NotifyAgent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un intento inicial mas tres reintentos, espaciados un minuto.
     *
     * El minuto es para que un agente que se esta reiniciando (deploy, OOM)
     * alcance a levantar. Ojo con la expectativa: esto solo rescata fallos
     * transitorios. Si el agente rechaza el payload por validacion, las cuatro
     * llamadas fallan igual — el mismo cuerpo produce el mismo error.
     */
    public int $tries = 4;

    public array $backoff = [60, 60, 60];

    public function __construct(public int $messageId) {}

    /**
     * Encola el aviso con la espera de `whatsapp.ai.debounce_seconds`, para que
     * los mensajes que el cliente manda seguidos lleguen juntos al agente.
     */
    public static function despachar(int $messageId): void
    {
        $pendiente = static::dispatch($messageId)->onQueue(config('whatsapp.queue'));

        if (($segundos = (int) config('whatsapp.ai.debounce_seconds', 0)) > 0) {
            $pendiente->delay(now()->addSeconds($segundos));
        }
    }

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

        // Re-check at run time: the human may have taken over since enqueue.
        if (! $message->conversation->agenteDebeResponder()) {
            return;
        }

        /*
        | Ráfaga: si el cliente ya mandó otro mensaje, este aviso sobra. El del
        | último mensaje lleva todos en `message.parts` (auditoría del 12/10:
        | el agente contestaba cada mensaje de una ráfaga por separado).
        */
        if ((int) config('whatsapp.ai.debounce_seconds', 0) > 0
            && $message->conversation->messages()
                ->where('direction', 'inbound')
                ->where('id', '>', $message->id)
                ->exists()) {
            return;
        }

        $response = Http::withToken((string) config('whatsapp.ai.token'))
            ->acceptJson()
            ->post($url, $payloads->event($message));

        // El cuerpo de la respuesta es lo unico que dice POR QUE rechazo el
        // agente. Sin esto, un 422 de validacion y un 422 de negocio se ven
        // identicos desde el CRM.
        if ($response->failed()) {
            Log::warning('WhatsApp: el agente IA rechazo el evento.', [
                'message'      => $this->messageId,
                'conversation' => $message->conversation->id,
                'gateway'      => $message->conversation->gateway,
                'status'       => $response->status(),
                'body'         => Str::limit($response->body(), 500),
                'attempt'      => $this->attempts(),
            ]);
        }

        $response->throw();
    }

    /**
     * Se agotaron los reintentos. Sin esto el evento moria en `failed_jobs` sin
     * que nadie se enterara: el cliente escribio y nunca hubo respuesta.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('WhatsApp: el agente IA no recibio el mensaje tras agotar los reintentos.', [
            'message' => $this->messageId,
            'tries'   => $this->tries,
            'error'   => $e->getMessage(),
        ]);

        app(AlertaAgente::class)->registrar(
            'agente_no_responde',
            MessageProxy::modelClass()::whereKey($this->messageId)->value('conversation_id'),
        );
    }
}
