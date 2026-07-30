<?php

namespace Webkul\Whatsapp\Gateways\CloudApi;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Webkul\Whatsapp\Gateways\BaseGateway;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\InboundMessage;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\Dto\StatusUpdate;
use Webkul\Whatsapp\Gateways\Dto\WebhookAuth;
use Webkul\Whatsapp\Gateways\Dto\WebhookSecurity;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Services\PhoneNumber;

/**
 * WhatsApp Cloud API (Meta). Addresses by phone, returns a wamid, batches
 * events as entry[].changes[].value and reports delivery receipts.
 */
class CloudApiGateway extends BaseGateway
{
    public function key(): string
    {
        return 'cloud_api';
    }

    public function capabilities(): Capabilities
    {
        // Only what is implemented today. 'contact' is still receive-only: it
        // needs a vCard builder the composer has no UI for yet.
        return new Capabilities(
            send: ['text', 'reply', 'emoji', 'image', 'audio', 'document', 'video', 'sticker', 'location'],
            receive: ['text', 'image', 'audio', 'document', 'video', 'sticker', 'location', 'contact'],
        );
    }

    public function webhookSecurity(): WebhookSecurity
    {
        return WebhookSecurity::SIGNATURE;
    }

    public function messagingWindowHours(): ?int
    {
        return 24;
    }

    /**
     * Meta's subscription handshake. Query keys arrive as hub.mode /
     * hub.verify_token / hub.challenge; PHP turns the dots into underscores.
     */
    public function verifyWebhook(Request $request): ?Response
    {
        if ($request->query('hub_mode') !== 'subscribe') {
            return null;
        }

        $expected = (string) ($this->config['verify_token'] ?? '');
        $token = (string) $request->query('hub_verify_token', '');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            return null;
        }

        return response((string) $request->query('hub_challenge', ''), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function authenticateWebhook(Request $request): WebhookAuth
    {
        $secret = (string) ($this->config['app_secret'] ?? '');

        if ($secret === '') {
            return WebhookAuth::fail('WHATSAPP_APP_SECRET is not configured.');
        }

        if (! $request->hasHeader('X-Hub-Signature-256')) {
            return WebhookAuth::fail('Missing X-Hub-Signature-256 header.');
        }

        if (! $this->hmacMatches($request, 'X-Hub-Signature-256', $secret, 'sha256', 'sha256=')) {
            return WebhookAuth::fail('X-Hub-Signature-256 does not match the payload.');
        }

        return WebhookAuth::ok();
    }

    public function parseWebhook(array $payload): InboundBatch
    {
        $messages = [];
        $statuses = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                $profileName = $value['contacts'][0]['profile']['name'] ?? null;

                foreach ($value['messages'] ?? [] as $message) {
                    if (empty($message['id']) || empty($message['from'])) {
                        continue;
                    }

                    $type = $this->normalizeType($message['type'] ?? 'unknown');

                    $media = $this->extractMedia($message, $type);

                    $messages[] = new InboundMessage(
                        providerMessageId: (string) $message['id'],
                        contact: new ContactIdentity(
                            phone: (string) $message['from'],
                            name: $profileName,
                        ),
                        type: $type,
                        body: $this->extractBody($message, $type),
                        replyToProviderId: $message['context']['id'] ?? null,
                        raw: $message,
                        mediaId: $media['id'] ?? null,
                        mediaMime: $media['mime'] ?? null,
                        mediaFilename: $media['filename'] ?? null,
                    );
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    if (empty($status['id']) || empty($status['status'])) {
                        continue;
                    }

                    $statuses[] = new StatusUpdate((string) $status['id'], (string) $status['status']);
                }
            }
        }

        return new InboundBatch($messages, $statuses);
    }

    public function sendText(Conversation $conversation, string $body, ?string $replyToProviderId = null): SendResult
    {
        return $this->deliver($conversation, 'text', ['body' => $body], $replyToProviderId);
    }

    /**
     * Two steps, same as the inbound path in reverse: upload the bytes to get an
     * opaque media id, then reference that id in the message. Uploading (rather
     * than handing Meta a public link) keeps customer attachments off any URL a
     * stranger could guess, and works even when the CRM is not reachable from
     * the internet.
     */
    public function sendMedia(Conversation $conversation, OutboundMedia $media, ?string $replyToProviderId = null): SendResult
    {
        $node = ['id' => $this->uploadMedia($media)];

        // Meta rejects `caption` on audio and sticker outright — 400, whole
        // message lost. The caller is responsible for having relocated it.
        if ($media->caption !== null && $media->caption !== '' && Capabilities::isCaptionable($media->type)) {
            $node['caption'] = $media->caption;
        }

        // Documents are the only type where the recipient sees a file name;
        // without it WhatsApp shows the opaque media id.
        if ($media->type === 'document' && $media->filename) {
            $node['filename'] = $media->filename;
        }

        return $this->deliver($conversation, $media->type, $node, $replyToProviderId);
    }

    public function sendLocation(Conversation $conversation, OutboundLocation $location, ?string $replyToProviderId = null): SendResult
    {
        return $this->deliver($conversation, 'location', array_filter([
            'latitude'  => (string) $location->latitude,
            'longitude' => (string) $location->longitude,
            'name'      => $location->name,
            'address'   => $location->address,
        ], fn ($value) => $value !== null && $value !== ''), $replyToProviderId);
    }

    /**
     * Upload a staged file and return Meta's media id (valid for 30 days).
     *
     * Streamed from disk rather than read into a string: a 100 MB document would
     * otherwise sit in the queue worker's memory twice over.
     */
    protected function uploadMedia(OutboundMedia $media): string
    {
        $path = $this->mediaPath($media);
        $handle = fopen($path, 'r');

        try {
            $response = Http::withToken($this->required('token'))
                ->acceptJson()
                ->attach('file', $handle, $media->filename ?: basename($path), array_filter([
                    'Content-Type' => $media->mime,
                ]))
                ->post($this->endpoint('media'), [
                    'messaging_product' => 'whatsapp',
                ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $response->throw();

        $mediaId = $response->json('id');

        if (! $mediaId) {
            throw new RuntimeException('Cloud API did not return a media id: '.$response->body());
        }

        return (string) $mediaId;
    }

    /**
     * POST one message and normalize the answer. Every send funnels through
     * here, so addressing, the reply context and the "no wamid" guard exist once.
     */
    protected function deliver(Conversation $conversation, string $type, array $node, ?string $replyToProviderId): SendResult
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => PhoneNumber::digits($conversation->wa_phone),
            'type'              => $type,
            $type               => $node,
        ];

        if ($replyToProviderId) {
            $payload['context'] = ['message_id' => $replyToProviderId];
        }

        $response = Http::withToken($this->required('token'))
            ->acceptJson()
            ->post($this->endpoint('messages'), $payload);

        $response->throw();

        $wamid = $response->json('messages.0.id');

        if (! $wamid) {
            throw new RuntimeException('Cloud API did not return a message id: '.$response->body());
        }

        return new SendResult($wamid);
    }

    /**
     * Two-step media fetch: resolve the media id to a short-lived CDN URL, then
     * download the bytes. Both calls carry the Bearer token — the CDN URL is not
     * public. Returns null (message persists text-only) on any failure.
     */
    public function downloadMedia(?string $mediaId): ?array
    {
        if (! $mediaId) {
            return null;
        }

        $token = $this->required('token');
        $version = (string) ($this->config['api_version'] ?? 'v21.0');

        $meta = Http::withToken($token)->acceptJson()
            ->get(sprintf('https://graph.facebook.com/%s/%s', $version, $mediaId));

        if (! $meta->successful() || ! ($url = $meta->json('url'))) {
            return null;
        }

        $file = Http::withToken($token)->get($url);

        if (! $file->successful()) {
            return null;
        }

        return [
            'contents' => $file->body(),
            'mime'     => $meta->json('mime_type'),
        ];
    }

    /**
     * Media id / mime / filename for the media message types. The node lives
     * under the type key ($message['image'], $message['document'], ...).
     */
    protected function extractMedia(array $message, string $type): array
    {
        if (! in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
            return [];
        }

        $node = $message[$type] ?? [];

        return [
            'id'       => $node['id'] ?? null,
            'mime'     => $node['mime_type'] ?? null,
            'filename' => $node['filename'] ?? null, // documents only
        ];
    }

    /**
     * Map Meta's type to ours ('contacts' is plural on the wire).
     */
    protected function normalizeType(string $type): string
    {
        return $type === 'contacts' ? 'contact' : $type;
    }

    /**
     * Human-readable body: text for text, caption for media, a label for the
     * structured types (so the conversation-list preview is never blank).
     */
    protected function extractBody(array $message, string $type): ?string
    {
        return match ($type) {
            'text'   => $message['text']['body'] ?? null,
            'image', 'video', 'document', 'audio' => $message[$type]['caption'] ?? null,
            'button'   => $message['button']['text'] ?? null,
            'location' => $message['location']['name'] ?? $message['location']['address'] ?? 'Ubicación',
            'contact'  => $message['contacts'][0]['name']['formatted_name'] ?? 'Contacto',
            default    => null,
        };
    }

    protected function endpoint(string $path): string
    {
        $version = (string) ($this->config['api_version'] ?? 'v21.0');

        return sprintf('https://graph.facebook.com/%s/%s/%s', $version, $this->required('phone_number_id'), $path);
    }
}
