<?php

namespace Webkul\Whatsapp\Gateways\Kommo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Whatsapp\Gateways\BaseGateway;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\ContactIdentity;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\InboundMessage;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\Dto\WebhookAuth;
use Webkul\Whatsapp\Gateways\Dto\WebhookSecurity;
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
 */
class KommoGateway extends BaseGateway
{
    public function key(): string
    {
        return 'kommo';
    }

    public function capabilities(): Capabilities
    {
        // The salesbot only relays the text field today.
        return new Capabilities(send: ['text'], receive: ['text']);
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

            $messages[] = new InboundMessage(
                providerMessageId: (string) $event['id'],
                contact: new ContactIdentity(
                    phone: $contactId ? $this->fetchContactPhone($contactId) : null,
                    providerId: $contactId,
                    name: data_get($event, 'author.name'),
                ),
                type: 'text',
                body: $event['text'] ?? null,
                // element_id and entity_id are the same lead id; element_id is
                // what the send path addresses.
                providerConversationId: isset($event['element_id']) ? (string) $event['element_id'] : null,
                raw: $event,
            );
        }

        // Kommo reports no delivery receipts.
        return new InboundBatch($messages);
    }

    public function sendText(Conversation $conversation, string $body, ?string $replyToProviderId = null): SendResult
    {
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
