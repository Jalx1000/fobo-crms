<?php

namespace Webkul\Whatsapp\Gateways\Kommo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Whatsapp\Gateways\BaseGateway;
use Webkul\Whatsapp\Gateways\Contracts\Gateway;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\InboundMessage;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\Dto\WebhookAuth;
use Webkul\Whatsapp\Gateways\Dto\WebhookSecurity;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Kommo (amoCRM).
 *
 * Inbound: form-encoded webhook using bracket notation
 * (message[add][0][text]), which PHP parses into a nested array for free.
 * It carries the lead id but NOT the phone — that needs a second call.
 *
 * Outbound: there is no "send message" endpoint. Sending is a two-step dance:
 *   1. PATCH /api/v4/leads/{id}   -> park the text in a custom field
 *   2. POST  /api/v2/salesbot/run -> launch the bot that reads it and delivers
 *
 * Consequences, all absorbed here:
 * - Addresses by Kommo lead id (provider_conversation_id), not phone.
 * - Sending returns no id, so one is synthesized.
 * - No delivery receipts: outbound stops at "sent".
 * - The webhook is unsigned, so the URL secret is the only credential.
 *
 * Modo mixto (`send_via`): Kommo solo recibe y cada envío se delega en otro
 * gateway (Cloud API), que llega al cliente por su teléfono y sabe mandar
 * plantillas, archivos, ubicación e interactivos. Ver
 * docs/specs/kommo-paridad-cloud-api.md.
 */
class KommoGateway extends BaseGateway
{
    /** Lo que entra por el webhook: texto y los adjuntos que mapea attachmentFrom(). */
    protected const RECEIVES = ['text', 'image', 'audio', 'video', 'document', 'sticker'];

    /** El gateway que envía en modo mixto, resuelto una sola vez. */
    protected ?Gateway $sender = null;

    public function key(): string
    {
        return 'kommo';
    }

    public function capabilities(): Capabilities
    {
        if ($sender = $this->sender()) {
            // Lo que se envía es lo del gateway que envía. Menos 'reply': lo que
            // el cliente escribió llegó por Kommo, así que su id no es un wamid
            // que Meta pueda citar.
            $send = array_values(array_diff($sender->capabilities()->send, ['reply']));

            return new Capabilities(send: $send, receive: self::RECEIVES);
        }

        // The salesbot only relays the text field today.
        return new Capabilities(send: ['text'], receive: self::RECEIVES);
    }

    /**
     * El gateway que envía en modo mixto, o null cuando envía el salesbot.
     *
     * Composición y no un cambio en GatewayManager: el resto del sistema sigue
     * preguntándole a UN gateway por conversación, y la mezcla queda encerrada acá.
     */
    protected function sender(): ?Gateway
    {
        $via = (string) ($this->config['send_via'] ?? $this->key());

        if ($via === '' || $via === $this->key()) {
            return null;
        }

        return $this->sender ??= app(GatewayManager::class)->driver($via);
    }

    /**
     * El gateway que envía, listo para dirigirse a la conversación por teléfono.
     */
    protected function senderFor(Conversation $conversation): Gateway
    {
        if (! $conversation->wa_phone) {
            throw new RuntimeException(
                "La conversación #{$conversation->id} no tiene teléfono: Kommo todavía no lo informó, "
                .'así que no se le puede enviar por WhatsApp. Se completa solo cuando el cliente vuelva a escribir.'
            );
        }

        return $this->sender();
    }

    /**
     * Solo se cita un mensaje que Meta conoce. Los ids de Kommo (entrantes, o
     * los "kommo-…" sintetizados) harían que Meta rechace el envío entero.
     */
    protected function citable(?string $replyToProviderId): ?string
    {
        return $replyToProviderId && str_starts_with($replyToProviderId, 'wamid.')
            ? $replyToProviderId
            : null;
    }

    public function sendMedia(Conversation $conversation, OutboundMedia $media, ?string $replyToProviderId = null): SendResult
    {
        if (! $this->sender()) {
            return parent::sendMedia($conversation, $media, $replyToProviderId);
        }

        return $this->senderFor($conversation)->sendMedia($conversation, $media, $this->citable($replyToProviderId));
    }

    public function sendLocation(Conversation $conversation, OutboundLocation $location, ?string $replyToProviderId = null): SendResult
    {
        if (! $this->sender()) {
            return parent::sendLocation($conversation, $location, $replyToProviderId);
        }

        return $this->senderFor($conversation)->sendLocation($conversation, $location, $this->citable($replyToProviderId));
    }

    public function sendInteractive(Conversation $conversation, OutboundInteractive $interactive, ?string $replyToProviderId = null): SendResult
    {
        if (! $this->sender()) {
            return parent::sendInteractive($conversation, $interactive, $replyToProviderId);
        }

        return $this->senderFor($conversation)->sendInteractive($conversation, $interactive, $this->citable($replyToProviderId));
    }

    public function sendTemplate(Conversation $conversation, OutboundTemplate $template, ?string $replyToProviderId = null): SendResult
    {
        if (! $this->sender()) {
            return parent::sendTemplate($conversation, $template, $replyToProviderId);
        }

        return $this->senderFor($conversation)->sendTemplate($conversation, $template, $this->citable($replyToProviderId));
    }

    public function messagingWindowHours(): ?int
    {
        // Kommo delivers over the WhatsApp Business API, so the 24h window
        // applies here too (the customer's error 3108 is that rule).
        return 24;
    }

    public function webhookSecurity(): WebhookSecurity
    {
        // Kommo signs nothing: it just POSTs to whatever URL is pasted in its
        // panel. The high-entropy secret in the URL is what authenticates it.
        return $this->allowsUnauthenticated()
            ? WebhookSecurity::NONE
            : WebhookSecurity::URL_SECRET;
    }

    public function authenticateWebhook(Request $request): WebhookAuth
    {
        if ($this->allowsUnauthenticated()) {
            return WebhookAuth::ok();
        }

        if (($this->config['webhook_secret'] ?? '') === '') {
            return WebhookAuth::fail('KOMMO_WEBHOOK_SECRET is not configured; Kommo webhooks cannot be authenticated.');
        }

        if (! $this->urlSecretMatches($request)) {
            return WebhookAuth::fail('URL secret does not match.');
        }

        return WebhookAuth::ok();
    }

    /**
     * KOMMO_WEBHOOK_INSECURE=true accepts every caller. Deliberate opt-in: with
     * it on, anyone who learns the URL can forge inbound messages.
     */
    protected function allowsUnauthenticated(): bool
    {
        return filter_var($this->config['allow_unauthenticated'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Kommo posts one delivery per event batch under message[add][].
     */
    public function parseWebhook(array $payload): InboundBatch
    {
        $messages = [];

        foreach (data_get($payload, 'message.add', []) as $event) {
            // Kommo echoes our own outgoing messages back. Ingesting them would
            // duplicate what we already recorded when we sent it.
            if (($event['type'] ?? 'incoming') !== 'incoming') {
                continue;
            }

            if (empty($event['id'])) {
                continue;
            }

            $contactId = isset($event['contact_id']) ? (string) $event['contact_id'] : null;

            $attachment = $this->attachmentFrom($event);

            $messages[] = new InboundMessage(
                providerMessageId: (string) $event['id'],
                contact: new ContactIdentity(
                    phone: $contactId ? $this->fetchContactPhone($contactId) : null,
                    providerId: $contactId,
                    name: data_get($event, 'author.name'),
                ),
                type: $attachment['type'],
                body: $attachment['body'],
                // element_id and entity_id are the same lead id; element_id is
                // what the send path addresses.
                providerConversationId: isset($event['element_id']) ? (string) $event['element_id'] : null,
                raw: $event,
                // El link de Kommo viaja como mediaId y downloadMedia() lo baja,
                // igual que Messenger: la ingesta no distingue de dónde vino.
                mediaId: $attachment['link'],
                mediaFilename: $attachment['name'],
            );
        }

        // Kommo reports no delivery receipts.
        return new InboundBatch($messages);
    }

    /**
     * Un evento de Kommo → (type, body, link, name).
     *
     * Kommo trae el adjunto en `attachment` (link/type/file_name) y repite el tipo
     * en `message_type`. Mapeo validado contra payloads reales en odontoking
     * (commit 0e85239). Lo que no trae archivo (vCard, ubicación) entra como texto
     * legible: el hilo nunca muestra una burbuja vacía.
     *
     * @return array{type: string, body: ?string, link: ?string, name: ?string}
     */
    protected function attachmentFrom(array $event): array
    {
        $link = data_get($event, 'attachment.link');
        $kind = data_get($event, 'attachment.type') ?? ($event['message_type'] ?? 'text');

        $map = [
            'picture'  => 'image',
            'image'    => 'image',
            'video'    => 'video',
            'voice'    => 'audio',
            'audio'    => 'audio',
            'file'     => 'document',
            'document' => 'document',
            'sticker'  => 'sticker',
        ];

        $text = $event['text'] ?? null;
        $text = $text === '' ? null : $text;

        if ($link && isset($map[$kind])) {
            return [
                'type' => $map[$kind],
                // El pie de foto que Kommo reenvía viaja como cuerpo.
                'body' => $text,
                'link' => (string) $link,
                'name' => data_get($event, 'attachment.file_name'),
            ];
        }

        $body = $text ?? match ($kind) {
            'contact'  => '[Contacto compartido]',
            'location' => '[Ubicación compartida]',
            default    => null,
        };

        return ['type' => 'text', 'body' => $body, 'link' => null, 'name' => null];
    }

    /**
     * Baja un adjunto entrante desde su link de Kommo.
     *
     * Hay que copiarlo en la ingesta: los links de amojo.kommo.com vencen en
     * pocos días (comprobado en odontoking).
     *
     * El link viene del payload, y el webhook de Kommo puede estar sin
     * autenticar (KOMMO_WEBHOOK_INSECURE): sin la lista de hosts, cualquiera
     * podría hacer que el servidor pida una URL interna (SSRF). Solo https y
     * solo dominios de Kommo, y con tope de tamaño.
     */
    public function downloadMedia(?string $mediaId): ?array
    {
        if (! $mediaId || ! $this->isKommoFileUrl($mediaId)) {
            if ($mediaId) {
                Log::warning('Kommo: adjunto con link fuera de Kommo, no se descarga', ['link' => $mediaId]);
            }

            return null;
        }

        try {
            $response = Http::timeout(20)->get($mediaId);

            if (! $response->successful() || $response->body() === '') {
                Log::warning('Kommo: no se pudo bajar el adjunto', ['status' => $response->status()]);

                return null;
            }

            $maxBytes = ((int) ($this->config['max_media_kb'] ?? 25600)) * 1024;

            if (strlen($response->body()) > $maxBytes) {
                Log::warning('Kommo: adjunto mayor a max_media_kb, descartado', ['bytes' => strlen($response->body())]);

                return null;
            }

            return [
                'contents' => $response->body(),
                'mime'     => $response->header('Content-Type'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Kommo: fallo la descarga del adjunto', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function isKommoFileUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach (['kommo.com', 'amocrm.com', 'amocrm.ru'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    public function sendText(Conversation $conversation, string $body, ?string $replyToProviderId = null): SendResult
    {
        if ($this->sender()) {
            return $this->senderFor($conversation)->sendText($conversation, $body, $this->citable($replyToProviderId));
        }

        $leadId = $conversation->provider_conversation_id;

        if (! $leadId) {
            throw new RuntimeException(
                "Kommo: conversation #{$conversation->id} has no provider_conversation_id (Kommo lead id); cannot address the message."
            );
        }

        $this->writeMessageField($leadId, $body);

        $this->runSalesbot($leadId);

        // Kommo returns no message id; synthesize one to preserve idempotency.
        return new SendResult('kommo-'.Str::uuid()->toString());
    }

    /**
     * Step 1: park the outgoing text in the lead's custom field.
     */
    protected function writeMessageField(string $leadId, string $body): void
    {
        $this->http()
            ->patch($this->url("/api/v4/leads/{$leadId}"), [
                'custom_fields_values' => [
                    [
                        'field_id' => (int) $this->required('message_field_id'),
                        'values'   => [['value' => $body]],
                    ],
                ],
            ])
            ->throw();
    }

    /**
     * Step 2: launch the bot that reads the field and delivers the message.
     */
    protected function runSalesbot(string $leadId): void
    {
        $this->http()
            ->post($this->url('/api/v2/salesbot/run'), [
                [
                    'bot_id'      => (int) $this->required('bot_id'),
                    'entity_id'   => (int) $leadId,
                    'entity_type' => (int) ($this->config['entity_type'] ?? 2), // 2 = lead
                ],
            ])
            ->throw();
    }

    /**
     * The webhook has no phone, so fetch the contact and read its phone field.
     *
     * Looked up by field_code = PHONE rather than by position: the n8n flow this
     * came from used custom_fields_values[0], which silently targets the wrong
     * field the day someone reorders the contact form in Kommo.
     */
    protected function fetchContactPhone(string $contactId): ?string
    {
        try {
            $response = $this->http()->get($this->url("/api/v4/contacts/{$contactId}"));

            if ($response->failed()) {
                Log::warning('Kommo: could not fetch contact', [
                    'contact_id' => $contactId,
                    'status'     => $response->status(),
                ]);

                return null;
            }

            $fields = $response->json('custom_fields_values') ?? [];

            foreach ($fields as $field) {
                if (($field['field_code'] ?? null) === 'PHONE') {
                    return data_get($field, 'values.0.value');
                }
            }

            // Fallback: first custom field, as the original n8n flow assumed.
            return data_get($fields, '0.values.0.value');
        } catch (\Throwable $e) {
            Log::warning('Kommo: contact lookup failed', [
                'contact_id' => $contactId,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->required('token'))->acceptJson();
    }

    protected function url(string $path): string
    {
        return 'https://'.$this->required('subdomain').'.kommo.com'.$path;
    }
}
