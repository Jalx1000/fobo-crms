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
        // Only what is implemented today.
        //
        // 'audio' is deliberately out: voice notes did not come through
        // reliably. Dropping it here removes the microphone and the audio
        // option from the composer AND makes the upload endpoint refuse audio,
        // so nothing half-working is offered. Put it back once sending a voice
        // note is verified end to end.
        return new Capabilities(
            send: ['text', 'reply', 'emoji', 'template', 'image', 'document', 'video', 'sticker'],
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
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => PhoneNumber::digits($conversation->wa_phone),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ];

        // Only Meta's own ids can be quoted. When the message being replied to
        // came in through another provider (Kommo hands out UUIDs), Meta rejects
        // the whole send — so drop the quote and still deliver the text.
        if ($replyToProviderId && str_starts_with($replyToProviderId, 'wamid.')) {
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
     * Send an approved template (HSM). $templateKey selects a name+language from
     * config('whatsapp.gateways.cloud_api.templates'); $variables fills the body
     * parameters ({{1}}, {{2}}, ...) in order.
     */
    public function sendTemplate(
        Conversation $conversation,
        string $templateKey,
        array $variables,
        ?string $language = null,
    ): SendResult {
        // A configured key (the scheduled reminder) resolves to its name and
        // language. Anything else is taken as the template's own name, which is
        // how the inbox sends one the agent picked from the catalogue.
        $template = $this->config['templates'][$templateKey] ?? [];

        $name = $template['name'] ?? $templateKey;
        $languageCode = $language ?? $template['language'] ?? 'es';

        $parameters = array_map(
            fn ($value) => ['type' => 'text', 'text' => (string) $value],
            array_values($variables),
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => PhoneNumber::digits($conversation->wa_phone),
            'type'              => 'template',
            'template'          => [
                'name'     => $name,
                'language' => ['code' => $languageCode],
                'components' => $parameters === [] ? [] : [
                    ['type' => 'body', 'parameters' => $parameters],
                ],
            ],
        ];

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
     * Media types this driver can send, and which extra fields each one accepts.
     * Sending a caption on audio or a filename on an image makes Meta reject the
     * whole request, so the payload is built strictly from this table.
     */
    private const MEDIA_FIELDS = [
        'image'    => ['caption'],
        'video'    => ['caption'],
        'document' => ['caption', 'filename'],
        'audio'    => [],
        'sticker'  => [],
    ];

    public function sendMedia(
        Conversation $conversation,
        string $type,
        string $link,
        ?string $caption = null,
        ?string $filename = null,
    ): SendResult {
        $allowed = self::MEDIA_FIELDS[$type] ?? null;

        if ($allowed === null) {
            throw new RuntimeException("Cloud API: unsupported media type [{$type}].");
        }

        $media = ['link' => $link];

        if ($caption !== null && $caption !== '' && in_array('caption', $allowed, true)) {
            $media['caption'] = $caption;
        }

        if ($filename !== null && $filename !== '' && in_array('filename', $allowed, true)) {
            $media['filename'] = $filename;
        }

        $response = Http::withToken($this->required('token'))
            ->acceptJson()
            ->post($this->endpoint('messages'), [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => PhoneNumber::digits($conversation->wa_phone),
                'type'              => $type,
                $type               => $media,
            ]);

        $response->throw();

        $wamid = $response->json('messages.0.id');

        if (! $wamid) {
            throw new RuntimeException('Cloud API did not return a message id: '.$response->body());
        }

        return new SendResult($wamid);
    }

    /**
     * Map Meta's type to ours ('contacts' is plural on the wire).
     */
    protected function normalizeType(string $type): string
    {
        return $type === 'contacts' ? 'contact' : $type;
    }

    /**
     * Human-readable body: text for text, caption for media.
     */
    protected function extractBody(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? null,
            'image', 'video', 'document', 'audio' => $message[$message['type']]['caption'] ?? null,
            'button' => $message['button']['text'] ?? null,
            default  => null,
        };
    }

    protected function endpoint(string $path): string
    {
        $version = (string) ($this->config['api_version'] ?? 'v21.0');

        return sprintf('https://graph.facebook.com/%s/%s/%s', $version, $this->required('phone_number_id'), $path);
    }
}
