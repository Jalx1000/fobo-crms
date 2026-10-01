<?php

namespace Webkul\Whatsapp\Gateways\Contracts;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\Dto\TemplateHeader;
use Webkul\Whatsapp\Gateways\Dto\WebhookAuth;
use Webkul\Whatsapp\Gateways\Dto\WebhookSecurity;
use Webkul\Whatsapp\Models\Conversation;

/**
 * A messaging provider adapter (WhatsApp Cloud API, Kommo, ...).
 *
 * Only one gateway is active per installation, selected by config
 * ('whatsapp.gateway'). Nothing provider-specific may leak past this contract:
 * the core speaks only in canonical DTOs.
 */
interface Gateway
{
    /**
     * Stable key used in config and persisted on conversations.
     */
    public function key(): string;

    /**
     * What this driver actually implements today — not what the provider
     * supports in theory. The composer renders from this.
     */
    public function capabilities(): Capabilities;

    /**
     * Length of the provider's free-text messaging window, in hours, or null if
     * it has none. WhatsApp gateways return 24; a non-WhatsApp channel returns
     * null (no window).
     */
    public function messagingWindowHours(): ?int;

    /*
    |--------------------------------------------------------------------------
    | Inbound
    |--------------------------------------------------------------------------
    */

    /**
     * How this provider authenticates its webhooks. Declared so a weak posture
     * can be surfaced rather than silently accepted.
     */
    public function webhookSecurity(): WebhookSecurity;

    /**
     * Registration handshake. Returns null when the provider has none
     * (Kommo just posts to whatever URL you paste in its panel).
     */
    public function verifyWebhook(Request $request): ?Response;

    /**
     * Authenticate one inbound delivery.
     */
    public function authenticateWebhook(Request $request): WebhookAuth;

    /**
     * Translate a provider payload into canonical events.
     */
    public function parseWebhook(array $payload): InboundBatch;

    /**
     * The option a contact picked, when the stored raw message is a tap on a
     * button or a list row rather than typed text.
     *
     * Returns ['kind' => ..., 'id' => ..., 'title' => ...] or null. Providers
     * with no interactive messages return null.
     */
    public function interactiveSelection(array $rawMessage): ?array;

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    /**
     * Send a plain text message.
     *
     * Receives the Conversation — not a phone string — so each driver picks its
     * own addressing: Cloud API uses wa_phone, Kommo uses
     * provider_conversation_id (its lead id).
     */
    public function sendText(Conversation $conversation, string $body, ?string $replyToProviderId = null): SendResult;

    /**
     * Send an approved template (HSM) — the only thing WhatsApp accepts once
     * the messaging window has closed.
     *
     * $templateKey is the template's name as the provider knows it; $variables
     * fill the body placeholders IN ORDER, because Meta matches them by
     * position, not by name. $language overrides the template's own default.
     *
     * $header sólo viaja cuando la plantilla declara un encabezado con
     * contenido variable (un DOCUMENT, una imagen, un TEXT con {{n}}). Meta
     * valida el mensaje contra la estructura exacta con la que se creó la
     * plantilla: omitir ese header —o mandar uno que la plantilla no declara—
     * hace fallar el envío entero con el error 132012.
     *
     * @param  array<int, string>  $variables
     *
     * @throws \Webkul\Whatsapp\Exceptions\CapabilityNotSupportedException
     */
    public function sendTemplate(
        Conversation $conversation,
        string $templateKey,
        array $variables = [],
        ?string $language = null,
        ?TemplateHeader $header = null,
    ): SendResult;

    /**
     * Send a file. The provider fetches it from the URL in the DTO, so that URL
     * has to be publicly reachable and serve the declared mime.
     *
     * @throws \Webkul\Whatsapp\Exceptions\CapabilityNotSupportedException
     */
    public function sendMedia(
        Conversation $conversation,
        OutboundMedia $media,
        ?string $replyToProviderId = null,
    ): SendResult;

    /**
     * Send a map pin, rendered natively by the client rather than as a link.
     *
     * @throws \Webkul\Whatsapp\Exceptions\CapabilityNotSupportedException
     */
    public function sendLocation(
        Conversation $conversation,
        OutboundLocation $location,
        ?string $replyToProviderId = null,
    ): SendResult;

    /**
     * Send a tappable menu: reply buttons or a list.
     *
     * What the customer picks comes back through the inbound webhook as an
     * interactive reply, which interactiveSelection() already resolves — so the
     * option's id is the contract's other half, not an afterthought.
     *
     * @throws \Webkul\Whatsapp\Exceptions\CapabilityNotSupportedException
     * @throws \Webkul\Whatsapp\Exceptions\InteractiveMessageException
     */
    public function sendInteractive(
        Conversation $conversation,
        OutboundInteractive $interactive,
        ?string $replyToProviderId = null,
    ): SendResult;

    /*
    |--------------------------------------------------------------------------
    | Inbound media
    |--------------------------------------------------------------------------
    | Providers do not put the file in the webhook, only a reference to it. The
    | two steps are split because the first is pure parsing of a payload we
    | already stored, and the second is a network call that can fail and be
    | retried on its own.
    */

    /**
     * The file a webhook message points at, or null when it carries none.
     *
     * @return array{id:string, mime:?string, filename:?string}|null
     */
    public function mediaReference(array $rawMessage): ?array;

    /**
     * Download that file. Null when the provider cannot serve it any more —
     * these references expire, which is why the download is not deferred.
     *
     * @return array{contents:string, mime:?string}|null
     */
    public function fetchMedia(string $mediaId): ?array;
}
