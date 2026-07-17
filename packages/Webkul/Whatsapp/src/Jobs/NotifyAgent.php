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
        if (! $message->conversation->aiEffective()) {
            return;
        }

        Http::withToken((string) config('whatsapp.ai.token'))
            ->acceptJson()
            ->post($url, $payloads->event($message))
            ->throw();
    }
}
