<?php

namespace Webkul\Messenger\Gateways;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Webkul\Messenger\Models\SocialConnection;
use Webkul\Messenger\Models\SocialConnectionProxy;
use Webkul\Messenger\Services\MessengerProfile;
use Webkul\Whatsapp\Exceptions\UnsupportedMessageException;
use Webkul\Whatsapp\Gateways\BaseGateway;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\InboundMessage;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\Dto\StatusUpdate;
use Webkul\Whatsapp\Gateways\Dto\WebhookAuth;
use Webkul\Whatsapp\Gateways\Dto\WebhookSecurity;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Facebook Messenger, as a channel of the shared inbox.
 *
 * Everything provider-specific stops here: the core receives the same canonical
 * DTOs it gets from WhatsApp, so Messenger threads land in the same store, link
 * to the same Person/Lead and are answered from the same composer.
 *
 * Addressing: a Messenger contact has no phone. The PSID (page-scoped id) is
 * the conversation key, and it is only meaningful for the page that issued it —
 * hence provider_account_id on the conversation, so a reply always goes out
 * through the page the message arrived on.
 */
class MessengerGateway extends BaseGateway
{
    /** Page connections resolved during this delivery, keyed by page id. */
    protected array $connections = [];

    public function __construct(array $config, protected MessengerProfile $profiles)
    {
        parent::__construct($config);
    }

    public function key(): string
    {
        return 'messenger';
    }

    /**
     * Capabilities are what the composer renders buttons from, so everything
     * listed here has to work: no sticker (Meta has no sticker upload) and no
     * location pin (the Send API cannot originate one).
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            send: ['text', 'reply', 'emoji', 'image', 'audio', 'video', 'document'],
            // A Messenger sticker arrives as an image and is stored as one, so
            // 'sticker' is deliberately absent: capabilities list what the
            // driver actually produces.
            receive: ['text', 'image', 'audio', 'video', 'document', 'location'],
        );
    }

    /**
     * Messenger has the same 24h standard messaging window as WhatsApp, so the
     * inbox banner and the outbound guard apply unchanged.
     */
    public function messagingWindowHours(): ?int
    {
        return 24;
    }

    public function webhookSecurity(): WebhookSecurity
    {
        return WebhookSecurity::SIGNATURE;
    }

    /**
     * Meta's subscription handshake. PHP rewrites dots in query keys, so the
     * parameters arrive as hub_mode/hub_verify_token — accept both spellings
     * rather than depending on that.
     */
    public function verifyWebhook(Request $request): ?Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge'));

        $expected = (string) ($this->config['verify_token'] ?? '');

        if ($mode !== 'subscribe' || $expected === '' || ! hash_equals($expected, $token)) {
            return null;
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function authenticateWebhook(Request $request): WebhookAuth
    {
        $secret = (string) ($this->config['app_secret'] ?? '');

        if ($secret === '') {
            return WebhookAuth::fail('MESSENGER_APP_SECRET is not configured');
        }

        return $this->hmacMatches($request, 'X-Hub-Signature-256', $secret, 'sha256', 'sha256=')
            ? WebhookAuth::ok()
            : WebhookAuth::fail('X-Hub-Signature-256 mismatch');
    }

    /**
     * Meta batches events per page entry. Anything for a page we do not have a
     * connection for is dropped: without its access token we could neither
     * name the sender nor ever reply.
     */
    public function parseWebhook(array $payload): InboundBatch
    {
        $messages = [];
        $statuses = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $item) {
                $item = (array) $item;

                $pageId = $this->pageIdOf($item, (string) ($entry['id'] ?? ''));

                if ($pageId === '') {
                    continue;
                }

                $connection = $this->connectionFor($pageId);

                if (! $connection) {
                    Log::warning('Messenger: event for an unconnected page.', ['page_id' => $pageId]);

                    continue;
                }

                foreach ($this->statusesOf($item) as $status) {
                    $statuses[] = $status;
                }

                if ($message = $this->messageOf($item, $connection, $pageId)) {
                    $messages[] = $message;
                }
            }
        }

        return new InboundBatch($messages, $statuses);
    }

    /**
     * Messenger attachments arrive as expiring CDN URLs rather than media ids,
     * so `mediaId` carries the URL and the fetch is a plain download. Capped:
     * the URL comes from the payload, and an unbounded read is a memory bomb.
     */
    public function downloadMedia(?string $mediaId): ?array
    {
        if (! $mediaId || ! str_starts_with($mediaId, 'https://')) {
            return null;
        }

        try {
            $response = Http::timeout(20)->get($mediaId);

            if (! $response->successful() || $response->body() === '') {
                return null;
            }

            $maxBytes = ((int) ($this->config['max_media_kb'] ?? 25600)) * 1024;

            if (strlen($response->body()) > $maxBytes) {
                Log::warning('Messenger: attachment exceeds max_media_kb, dropped.', [
                    'bytes' => strlen($response->body()),
                ]);

                return null;
            }

            return [
                'contents' => $response->body(),
                'mime'     => $response->header('Content-Type'),
            ];
        } catch (Throwable $e) {
            Log::warning('Messenger: attachment download failed.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function sendText(Conversation $conversation, string $body, ?string $replyToProviderId = null): SendResult
    {
        $connection = $this->connectionForConversation($conversation);

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->post($this->endpoint($connection->account_id), array_filter([
                'recipient'      => ['id' => $this->psidOf($conversation)],
                'messaging_type' => 'RESPONSE',
                'message'        => ['text' => $body],
                'reply_to'       => $replyToProviderId ? ['mid' => $replyToProviderId] : null,
            ]));

        return new SendResult($this->messageIdFrom($response));
    }

    /**
     * Send an attachment.
     *
     * The file is uploaded in the same request (multipart) rather than by URL:
     * the CRM may sit behind auth or a private network, and Meta has to be able
     * to fetch the bytes itself.
     */
    public function sendMedia(Conversation $conversation, OutboundMedia $media, ?string $replyToProviderId = null): SendResult
    {
        $connection = $this->connectionForConversation($conversation);

        $path = $this->mediaPath($media);

        $this->guardSize($path);

        $message = [
            'attachment' => [
                'type'    => $this->attachmentType($media->type),
                'payload' => ['is_reusable' => false],
            ],
        ];

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->attach(
                'filedata',
                fopen($path, 'r'),
                $media->filename ?: basename($media->path),
                array_filter(['Content-Type' => $media->mime]),
            )
            // With attach() the payload travels as multipart, so every field has
            // to be a string — the nested objects go as JSON.
            ->post($this->endpoint($connection->account_id), array_filter([
                'recipient'      => json_encode(['id' => $this->psidOf($conversation)]),
                'messaging_type' => 'RESPONSE',
                'message'        => json_encode($message),
                'reply_to'       => $replyToProviderId ? json_encode(['mid' => $replyToProviderId]) : null,
            ]));

        $mid = $this->messageIdFrom($response);

        // Messenger attachments carry no caption field. The composer already
        // splits the caption off for types it knows cannot hold one; for the
        // rest it arrives here, and dropping it would be silent data loss — the
        // advisor watched their text leave the composer. It follows the file.
        if ($media->caption) {
            $this->sendText($conversation, $media->caption);
        }

        return new SendResult($mid);
    }

    /**
     * Meta's attachment types. A document is a "file"; there is no sticker
     * upload, which is why capabilities() does not offer one.
     */
    protected function attachmentType(string $type): string
    {
        return match ($type) {
            'image', 'audio', 'video' => $type,
            'document'                => 'file',
            default                   => throw UnsupportedMessageException::type($this->key(), $type),
        };
    }

    /**
     * Meta caps attachments at 25 MB. The composer validates against WhatsApp's
     * table, which allows 100 MB documents, so the check has to happen here —
     * otherwise a big PDF comes back as an opaque Graph error minutes later.
     */
    protected function guardSize(string $path): void
    {
        $maxBytes = 25 * 1024 * 1024;

        if (filesize($path) > $maxBytes) {
            throw new RuntimeException('Messenger no acepta archivos de más de 25 MB.');
        }
    }

    protected function psidOf(Conversation $conversation): string
    {
        $psid = (string) $conversation->provider_conversation_id;

        if ($psid === '') {
            throw new RuntimeException("La conversación #{$conversation->id} no tiene PSID de Messenger.");
        }

        return $psid;
    }

    /**
     * The provider's message id, or a readable failure. Graph reports errors
     * inside a 200 body as often as by status code.
     */
    protected function messageIdFrom($response): string
    {
        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Messenger rechazó el envío (%s): %s',
                $response->status(),
                (string) $response->json('error.message', $response->body()),
            ));
        }

        $mid = $response->json('message_id') ?: $response->json('mid');

        if (! $mid) {
            throw new RuntimeException('Messenger no devolvió un id de mensaje.');
        }

        return (string) $mid;
    }

    /**
     * Which page an event belongs to. On an echo the page is the sender; on
     * everything else it is the recipient. `entry.id` is the fallback.
     */
    protected function pageIdOf(array $item, string $entryId): string
    {
        $isEcho = (bool) data_get($item, 'message.is_echo', false);

        return (string) ($isEcho
            ? data_get($item, 'sender.id', $entryId)
            : data_get($item, 'recipient.id', $entryId));
    }

    /**
     * Delivery receipts. Messenger reports reads by watermark (a timestamp, not
     * a message id), which cannot be mapped onto a single message — only the
     * per-mid delivery events are translated.
     */
    protected function statusesOf(array $item): array
    {
        return array_map(
            fn ($mid) => new StatusUpdate((string) $mid, 'delivered'),
            (array) data_get($item, 'delivery.mids', []),
        );
    }

    /**
     * One messaging event → one canonical message, or null when it carries
     * nothing a human would see in a thread.
     */
    protected function messageOf(array $item, SocialConnection $connection, string $pageId): ?InboundMessage
    {
        $isEcho = (bool) data_get($item, 'message.is_echo', false);

        $psid = (string) ($isEcho
            ? data_get($item, 'recipient.id', '')
            : data_get($item, 'sender.id', ''));

        if ($psid === '') {
            return null;
        }

        $mid = (string) (data_get($item, 'message.mid')
            ?? data_get($item, 'postback.mid')
            ?? data_get($item, 'reaction.mid')
            ?? '');

        if ($mid === '') {
            return null;
        }

        $content = $this->contentOf($item);

        if (! $content) {
            return null;
        }

        return new InboundMessage(
            providerMessageId: $mid,
            // The profile lookup is skipped once we already know the contact,
            // so a busy thread costs no extra Graph calls.
            contact: $this->identityOf($connection, $psid, $isEcho),
            type: $content['type'],
            body: $content['body'],
            providerConversationId: $psid,
            replyToProviderId: data_get($item, 'message.reply_to.mid'),
            raw: $content['raw'],
            mediaId: $content['media_url'],
            mediaMime: null,
            mediaFilename: null,
            providerAccountId: $pageId,
            direction: $isEcho ? 'outbound' : 'inbound',
        );
    }

    /**
     * Normalize the many event shapes into (type, body, media, raw).
     *
     * Postbacks and reactions have no thread representation of their own, so
     * they are recorded as the text they stand for instead of being dropped —
     * "the customer tapped Comprar" is exactly the context an advisor needs.
     */
    protected function contentOf(array $item): ?array
    {
        if ($postback = data_get($item, 'postback')) {
            return [
                'type'      => 'text',
                'body'      => (string) ($postback['title'] ?? $postback['payload'] ?? 'Acción'),
                'media_url' => null,
                'raw'       => $item,
            ];
        }

        if ($reaction = data_get($item, 'reaction')) {
            if (($reaction['action'] ?? '') === 'unreact') {
                return null;
            }

            return [
                'type'      => 'text',
                'body'      => trim('Reaccionó '.(string) ($reaction['emoji'] ?? $reaction['reaction'] ?? '')),
                'media_url' => null,
                'raw'       => $item,
            ];
        }

        $message = (array) data_get($item, 'message', []);
        $attachment = $message['attachments'][0] ?? null;

        if (! $attachment) {
            $text = $message['text'] ?? null;

            return $text === null || $text === ''
                ? null
                : ['type' => 'text', 'body' => (string) $text, 'media_url' => null, 'raw' => $item];
        }

        // A location pin is structured data, not a file: the thread renders it
        // from payload.location, the same shape the WhatsApp driver produces.
        if (($attachment['type'] ?? '') === 'location') {
            $coordinates = (array) data_get($attachment, 'payload.coordinates', []);

            if (! isset($coordinates['lat'], $coordinates['long'])) {
                return null;
            }

            return [
                'type'      => 'location',
                'body'      => $attachment['title'] ?? null,
                'media_url' => null,
                'raw'       => $item + ['location' => [
                    'latitude'  => $coordinates['lat'],
                    'longitude' => $coordinates['long'],
                    'name'      => $attachment['title'] ?? null,
                ]],
            ];
        }

        // A shared link comes through as a "fallback" attachment with no file
        // behind it. Recorded as the link it is, rather than as a document that
        // would render an empty attachment card.
        if (($attachment['type'] ?? '') === 'fallback') {
            $body = $message['text'] ?? $attachment['title'] ?? data_get($attachment, 'payload.url') ?? $attachment['url'] ?? null;

            return $body === null
                ? null
                : ['type' => 'text', 'body' => (string) $body, 'media_url' => null, 'raw' => $item];
        }

        return [
            'type'      => $this->normalizeType((string) ($attachment['type'] ?? 'file')),
            'body'      => $message['text'] ?? null,
            'media_url' => data_get($attachment, 'payload.url'),
            'raw'       => $item,
        ];
    }

    /**
     * Meta's attachment types mapped onto the inbox's canonical set. A sticker
     * arrives as an image carrying a sticker_id.
     */
    protected function normalizeType(string $type): string
    {
        return match ($type) {
            'image', 'audio', 'video' => $type,
            'file'                    => 'document',
            default                   => 'document',
        };
    }

    /**
     * Who is writing. Meta sends no name in the webhook, so it takes a Graph
     * call — made only for a contact we have never seen, and never for an echo
     * (that is us).
     */
    protected function identityOf(SocialConnection $connection, string $psid, bool $isEcho): ContactIdentity
    {
        if ($isEcho || ! ($this->config['fetch_profile'] ?? true)) {
            return new ContactIdentity(providerId: $psid);
        }

        $profile = $this->profiles->fetch($connection, $psid);

        return new ContactIdentity(
            providerId: $psid,
            name: $profile['name'] ?? null,
            avatarUrl: $profile['avatar_url'] ?? null,
        );
    }

    /**
     * The page that owns a conversation. Keyed by the account recorded at
     * ingestion; a single-page install still resolves when that is missing
     * (conversations created before the column existed).
     */
    protected function connectionForConversation(Conversation $conversation): SocialConnection
    {
        $connection = $conversation->provider_account_id
            ? $this->connectionFor((string) $conversation->provider_account_id)
            : $this->onlyConnection();

        if (! $connection || ! $connection->access_token) {
            throw new RuntimeException(
                'La página de Messenger de esta conversación no está conectada. '
                .'Ejecutá `php artisan messenger:connect-page`.'
            );
        }

        return $connection;
    }

    /**
     * The single connected page, when there is exactly one. With several pages
     * connected an unattributed conversation is ambiguous, and guessing would
     * send a customer's reply out through the wrong brand.
     */
    protected function onlyConnection(): ?SocialConnection
    {
        $connections = SocialConnectionProxy::modelClass()::forChannel($this->key())->limit(2)->get();

        return $connections->count() === 1 ? $connections->first() : null;
    }

    protected function connectionFor(string $pageId): ?SocialConnection
    {
        // One delivery can batch dozens of events for the same page; look the
        // connection up once.
        return $this->connections[$pageId] ??= SocialConnectionProxy::modelClass()::forChannel($this->key())
            ->where('account_id', $pageId)
            ->first();
    }

    protected function endpoint(string $pageId): string
    {
        $version = trim((string) ($this->config['graph_version'] ?? 'v25.0'), '/');

        return "https://graph.facebook.com/{$version}/{$pageId}/messages";
    }
}
