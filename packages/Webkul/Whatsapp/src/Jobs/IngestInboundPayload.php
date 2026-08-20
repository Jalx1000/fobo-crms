<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Whatsapp\Gateways\Contracts\Gateway;
use Webkul\Whatsapp\Gateways\Dto\InboundMessage;
use Webkul\Whatsapp\Gateways\Dto\StatusUpdate;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * Persists whatever the active gateway hands back. Knows nothing about any
 * provider's payload shape — that lives in the driver.
 */
class IngestInboundPayload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string  $gatewayKey  Driver that received the payload.
     * @param  array  $payload  Raw provider payload.
     */
    public function __construct(
        public string $gatewayKey,
        public array $payload,
    ) {}

    public function handle(GatewayManager $gateways, ConversationResolver $resolver): void
    {
        $gateway = $gateways->driver($this->gatewayKey);

        $batch = $gateway->parseWebhook($this->payload);

        if (! $batch->messages && ! $batch->statuses) {
            // An authenticated delivery that yields nothing almost always means
            // the active gateway does not match the traffic hitting the webhook
            // (e.g. WHATSAPP_GATEWAY=kommo while Meta posts here). Dropping it
            // silently loses real customer messages, so say so loudly.
            Log::warning('WhatsApp: webhook payload produced no events — is WHATSAPP_GATEWAY correct for this traffic?', [
                'gateway'      => $this->gatewayKey,
                'payload_keys' => array_keys($this->payload),
            ]);

            return;
        }

        foreach ($batch->messages as $message) {
            $this->persistMessage($message, $resolver, $gateway);
        }

        foreach ($batch->statuses as $status) {
            $this->applyStatus($status);
        }
    }

    /**
     * Idempotent by the provider's message id: webhooks get retried.
     */
    protected function persistMessage(InboundMessage $inbound, ConversationResolver $resolver, Gateway $gateway): void
    {
        $model = MessageProxy::modelClass();

        if ($model::where('wa_message_id', $inbound->providerMessageId)->exists()) {
            return;
        }

        $conversation = $resolver->resolve(
            $inbound->contact,
            $inbound->providerConversationId,
            $this->gatewayKey,
            $inbound->providerAccountId,
        );

        if (! $conversation) {
            return;
        }

        $media = $this->storeMedia($inbound, $gateway, $conversation->id);

        // An echo is something the team already sent, from the provider's own
        // app. It belongs in the thread as history, but it is not a customer
        // message: it must not reopen the window, add to unread, or wake the AI.
        $isEcho = $inbound->isEcho();

        $created = $model::create([
            'conversation_id' => $conversation->id,
            'direction'       => $isEcho ? 'outbound' : 'inbound',
            'type'            => $inbound->type,
            'body'            => $inbound->body,
            'media_path'      => $media['path'] ?? null,
            'media_mime'      => $media['mime'] ?? $inbound->mediaMime,
            'media_name'      => $media['name'] ?? $inbound->mediaFilename,
            'wa_message_id'   => $inbound->providerMessageId,
            'reply_to_id'     => $this->resolveReplyTo($inbound->replyToProviderId),
            'status'          => $isEcho ? 'sent' : 'delivered',
            'sender'          => $isEcho ? 'human' : 'contact',
            'payload'         => $inbound->raw,
        ]);

        $conversation->forceFill(array_filter([
            'last_message_at' => now(),
            'last_inbound_at' => $isEcho ? null : now(), // reopens the 24h messaging window
            'unread_count'    => $isEcho ? null : $conversation->unread_count + 1,
        ], fn ($value) => $value !== null))->save();

        if ($isEcho) {
            return;
        }

        // Hand the message to the AI agent — but only while it is enabled for
        // this conversation. Off = a human is handling it.
        if ($conversation->aiEffective()) {
            NotifyAgent::dispatch($created->id)->onQueue(config('whatsapp.queue'));
        }
    }

    /**
     * Never regress: providers can deliver receipts out of order.
     */
    protected function applyStatus(StatusUpdate $update): void
    {
        $message = MessageProxy::modelClass()::where('wa_message_id', $update->providerMessageId)->first();

        if (! $message) {
            return;
        }

        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 3];

        if (($rank[$update->status] ?? 0) < ($rank[$message->status] ?? 0)) {
            return;
        }

        $message->update(['status' => $update->status]);
    }

    protected function resolveReplyTo(?string $providerId): ?int
    {
        if (! $providerId) {
            return null;
        }

        return MessageProxy::modelClass()::where('wa_message_id', $providerId)->value('id');
    }

    /**
     * Download an inbound media file through the gateway and store it on the
     * public disk. Returns ['path' => url, 'mime' => ..., 'name' => ...] or an
     * empty array — a failure must never drop the message, only its attachment.
     */
    protected function storeMedia(InboundMessage $inbound, Gateway $gateway, int $conversationId): array
    {
        if (! $inbound->mediaId) {
            return [];
        }

        try {
            $file = $gateway->downloadMedia($inbound->mediaId);

            if (! $file || ($file['contents'] ?? '') === '') {
                Log::warning('WhatsApp: media download returned nothing', [
                    'media_id' => $inbound->mediaId,
                    'type'     => $inbound->type,
                ]);

                return [];
            }

            $mime = $file['mime'] ?? $inbound->mediaMime;
            $ext = $this->extensionFor($mime, $inbound->mediaFilename);
            $path = 'whatsapp/media/'.$conversationId.'/'.Str::random(24).($ext ? '.'.$ext : '');

            Storage::disk('public')->put($path, $file['contents']);

            return [
                'path' => Storage::disk('public')->url($path),
                'mime' => $mime,
                'name' => $inbound->mediaFilename ?: ($inbound->type.($ext ? '.'.$ext : '')),
            ];
        } catch (Throwable $e) {
            Log::warning('WhatsApp: media download failed', [
                'media_id' => $inbound->mediaId,
                'error'    => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Best-effort file extension from the mime type (params like "; codecs=opus"
     * stripped) or the original filename.
     */
    protected function extensionFor(?string $mime, ?string $filename): ?string
    {
        if ($filename && str_contains($filename, '.')) {
            return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        }

        $mime = trim(explode(';', (string) $mime)[0]);

        return match ($mime) {
            'image/jpeg'                    => 'jpg',
            'image/png'                     => 'png',
            'image/webp'                    => 'webp',
            'image/gif'                     => 'gif',
            'audio/ogg'                     => 'ogg',
            'audio/mpeg'                    => 'mp3',
            'audio/mp4', 'audio/aac'        => 'm4a',
            'audio/amr'                     => 'amr',
            'video/mp4'                     => 'mp4',
            'video/3gpp'                    => '3gp',
            'application/pdf'               => 'pdf',
            'application/msword'            => 'doc',
            'application/vnd.ms-excel'      => 'xls',
            default                         => null,
        };
    }
}
