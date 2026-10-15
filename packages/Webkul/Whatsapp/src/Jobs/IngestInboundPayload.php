<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Whatsapp\Gateways\Dto\InboundMessage;
use Webkul\Whatsapp\Gateways\Dto\StatusUpdate;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * Persists whatever the active gateway hands back. Knows nothing about any
 * provider's payload shape - that lives in the driver.
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
        $batch = $gateways->driver($this->gatewayKey)->parseWebhook($this->payload);

        if (! $batch->messages && ! $batch->statuses) {
            // An authenticated delivery that yields nothing almost always means
            // the active gateway does not match the traffic hitting the webhook
            // (e.g. WHATSAPP_GATEWAY=kommo while Meta posts here). Dropping it
            // silently loses real customer messages, so say so loudly.
            Log::warning('WhatsApp: webhook payload produced no events - is WHATSAPP_GATEWAY correct for this traffic?', [
                'gateway'      => $this->gatewayKey,
                'payload_keys' => array_keys($this->payload),
            ]);

            return;
        }

        foreach ($batch->messages as $message) {
            $this->persistMessage($message, $resolver);
        }

        foreach ($batch->statuses as $status) {
            $this->applyStatus($status);
        }
    }

    /**
     * Idempotent by the provider's message id: webhooks get retried.
     */
    protected function persistMessage(InboundMessage $inbound, ConversationResolver $resolver): void
    {
        $model = MessageProxy::modelClass();

        if ($model::where('wa_message_id', $inbound->providerMessageId)->exists()) {
            return;
        }

        $conversation = $resolver->resolve($inbound->contact, $inbound->providerConversationId, $this->gatewayKey);

        if (! $conversation) {
            return;
        }

        $media = $this->storeMedia($inbound);

        $created = $model::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'type'            => $inbound->type,
            'body'            => $inbound->body,
            'wa_message_id'   => $inbound->providerMessageId,
            'reply_to_id'     => $this->resolveReplyTo($inbound->replyToProviderId),
            'status'          => 'delivered',
            'sender'          => 'contact',
            'payload'         => $inbound->raw,
            'media_path'      => $media['path'],
            'media_mime'      => $media['mime'],
            'media_name'      => $media['name'],
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(), // reopens the 24h messaging window
            'unread_count'    => $conversation->unread_count + 1,
        ])->save();

        // Hand the message to the AI agent - but only while it is enabled for
        // this conversation. Off = a human is handling it.
        if ($conversation->aiEffective()) {
            NotifyAgent::dispatch($created->id)->onQueue(config('whatsapp.queue'));
        }
    }

    /**
     * Download an inbound attachment into our own storage before the provider's
     * link expires (Kommo's amojo URLs are short-lived), and return what the
     * message row should keep. The inbox renders from these columns.
     *
     * Served through our own media route so the Content-Type matches what was
     * stored - the same path outbound attachments take.
     *
     * Fail-soft: if the download breaks, the message is still saved and the raw
     * provider link is kept as a best-effort fallback (it may still resolve
     * while fresh). A broken attachment must never drop the whole message.
     *
     * @return array{path: ?string, mime: ?string, name: ?string}
     */
    protected function storeMedia(InboundMessage $inbound): array
    {
        if (! $inbound->mediaLink) {
            return ['path' => null, 'mime' => null, 'name' => null];
        }

        try {
            $response = Http::timeout(20)->get($inbound->mediaLink);

            if (! $response->successful()) {
                throw new \RuntimeException("download returned HTTP {$response->status()}");
            }

            $mime = $response->header('Content-Type') ?: 'application/octet-stream';
            // Drop any "; charset=..." the server tacks on.
            $mime = trim(explode(';', $mime)[0]);

            $extension = $this->extensionFor($inbound->mediaName, $mime);
            $filename = Str::random(40).($extension ? '.'.$extension : '');
            $path = 'whatsapp/'.$filename;

            Storage::disk('public')->put($path, $response->body());

            return [
                'path' => route('whatsapp.media', ['file' => $filename]),
                'mime' => $mime,
                'name' => $inbound->mediaName,
            ];
        } catch (\Throwable $e) {
            Log::warning('WhatsApp: could not download inbound attachment; keeping raw link.', [
                'provider_message_id' => $inbound->providerMessageId,
                'link'                => $inbound->mediaLink,
                'error'               => $e->getMessage(),
            ]);

            // Fallback: the front renders media.url straight from this, so a
            // still-valid provider link at least shows until it expires.
            return ['path' => $inbound->mediaLink, 'mime' => $inbound->mediaMime, 'name' => $inbound->mediaName];
        }
    }

    /**
     * A file extension for the stored attachment: the one the provider gave, or
     * one derived from the Content-Type when it didn't.
     */
    protected function extensionFor(?string $name, string $mime): ?string
    {
        $fromName = $name ? pathinfo($name, PATHINFO_EXTENSION) : '';

        if ($fromName !== '') {
            return strtolower($fromName);
        }

        return match ($mime) {
            'image/jpeg'                => 'jpg',
            'image/png'                 => 'png',
            'image/webp'                => 'webp',
            'image/gif'                 => 'gif',
            'audio/ogg'                 => 'ogg',
            'audio/mpeg'                => 'mp3',
            'audio/mp4', 'audio/x-m4a'  => 'm4a',
            'audio/amr'                 => 'amr',
            'video/mp4'                 => 'mp4',
            'application/pdf'           => 'pdf',
            default                     => null,
        };
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
}
