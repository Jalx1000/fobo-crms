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
use Webkul\Whatsapp\Gateways\Dto\DownloadedMedia;
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
use Webkul\Whatsapp\Gateways\UnsupportedCapability;
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
 * MODO MIXTO (`send_via`): Kommo solo recibe y cada envío se delega en otro
 * gateway —Cloud API—, que llega al cliente por su teléfono y sabe mandar
 * plantillas, archivos, ubicación e interactivos.
 *
 * Es composición y no un cambio en GatewayManager a propósito: el resto del
 * sistema sigue preguntándole a UN gateway por conversación, ningún controlador
 * ni job cambia, y la mezcla queda encerrada en esta clase.
 */
class KommoGateway extends BaseGateway
{
    /** Lo que entra por el webhook del salesbot. */
    protected const RECEIVES = ['text'];

    /** El gateway que envía en modo mixto, resuelto una sola vez. */
    protected ?Gateway $sender = null;

    public function key(): string
    {
        return 'kommo';
    }

    public function capabilities(): Capabilities
    {
        if ($sender = $this->sender()) {
            // Lo que se puede enviar es lo del gateway que envía. Menos 'reply':
            // lo que el cliente escribió llegó por Kommo, así que su id no es un
            // wamid que Meta pueda citar.
            return new Capabilities(
                send: array_values(array_diff($sender->capabilities()->send, ['reply'])),
                receive: self::RECEIVES,
            );
        }

        // The salesbot only relays the text field today.
        return new Capabilities(send: ['text'], receive: self::RECEIVES);
    }

    /**
     * El gateway que envía en modo mixto, o null cuando envía el salesbot.
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
     *
     * Cloud API direcciona por número, no por lead id. Una conversación de Kommo
     * sin teléfono todavía no lo informó, y el error lo dice así en vez de
     * devolver un fallo opaco de la Graph API.
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
     * Solo se cita un mensaje que Meta conoce.
     *
     * Los ids de Kommo —los entrantes y los «kommo-…» que sintetizamos al
     * enviar— harían que Meta rechace el envío entero, no solo la cita.
     */
    protected function citable(?string $replyToProviderId): ?string
    {
        return $replyToProviderId && str_starts_with($replyToProviderId, 'wamid.')
            ? $replyToProviderId
            : null;
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
        if ($this->sender()) {
            return $this->senderFor($conversation)
                ->sendText($conversation, $body, $this->citable($replyToProviderId));
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

    /*
    |--------------------------------------------------------------------------
    | Capacidades no soportadas
    |--------------------------------------------------------------------------
    | Kommo entrega el texto parkeándolo en un campo del lead y disparando un
    | salesbot: no hay ruta para adjuntar un archivo ni una ubicación.
    |
    | Se lanza la excepción en vez de degradar a "te paso el link de la foto":
    | un texto con una URL no es una foto, y hacer pasar uno por otra le haría
    | creer al agente que el cliente vio algo que nunca vio.
    */

    public function sendMedia(Conversation $conversation, OutboundMedia $media): SendResult
    {
        if ($this->sender()) {
            return $this->senderFor($conversation)->sendMedia($conversation, $media);
        }

        throw UnsupportedCapability::para($this->key(), 'archivos');
    }

    public function sendLocation(Conversation $conversation, OutboundLocation $location): SendResult
    {
        if ($this->sender()) {
            return $this->senderFor($conversation)->sendLocation($conversation, $location);
        }

        throw UnsupportedCapability::para($this->key(), 'ubicaciones');
    }

    public function sendTemplate(Conversation $conversation, OutboundTemplate $template): SendResult
    {
        if ($this->sender()) {
            return $this->senderFor($conversation)->sendTemplate($conversation, $template);
        }

        throw UnsupportedCapability::para($this->key(), 'plantillas');
    }

    /**
     * Botones: nativos en modo mixto, texto numerado por salesbot.
     *
     * En modo mixto los manda Cloud API y el cliente ve botones de verdad. Sin
     * modo mixto se degrada a texto numerado en lugar de lanzar —que es lo que
     * hacen Sensia e imprimir, donde el mensaje falla y el cliente no recibe
     * nada—: un cliente esperando una pregunta no se merece un silencio porque
     * su conversación entró por otro gateway. Numeradas, puede contestar «2», y
     * la numeración coincide con el id de la opción (`opt:1` → «1.»).
     */
    public function sendInteractive(
        Conversation $conversation,
        OutboundInteractive $interactive,
        ?string $replyToProviderId = null,
    ): SendResult {
        if ($this->sender()) {
            return $this->senderFor($conversation)
                ->sendInteractive($conversation, $interactive, $this->citable($replyToProviderId));
        }

        return $this->sendText($conversation, $this->comoTexto($interactive), $replyToProviderId);
    }

    /**
     * Aplana el menú a texto: el enunciado, las opciones numeradas y el footer.
     */
    protected function comoTexto(OutboundInteractive $interactive): string
    {
        $lineas = [$interactive->body, ''];

        foreach ($interactive->options as $i => $opcion) {
            $lineas[] = ($i + 1).'. '.$opcion['title'];
        }

        if ($interactive->footer) {
            $lineas[] = '';
            $lineas[] = $interactive->footer;
        }

        return implode("\n", $lineas);
    }

    public function uploadMedia(string $absolutePath, string $mimeType): ?string
    {
        return null;
    }

    public function downloadMedia(string $mediaId, int $maxBytes): ?DownloadedMedia
    {
        throw UnsupportedCapability::para($this->key(), 'archivos');
    }
}
