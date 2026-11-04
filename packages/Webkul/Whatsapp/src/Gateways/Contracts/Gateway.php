<?php

namespace Webkul\Whatsapp\Gateways\Contracts;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
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
     * What this driver actually implements today - not what the provider
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
     * Fetch the bytes for an inbound media message by the provider's media id.
     * Returns ['contents' => binary, 'mime' => string|null] or null when the
     * provider has no media API (or the fetch fails). Drivers without media
     * inherit the null default from BaseGateway.
     */
    public function downloadMedia(?string $mediaId): ?array;

    /*
    |--------------------------------------------------------------------------
    | Outbound
    |--------------------------------------------------------------------------
    */

    /**
     * Send a plain text message.
     *
     * Receives the Conversation - not a phone string - so each driver picks its
     * own addressing: Cloud API uses wa_phone, Kommo uses
     * provider_conversation_id (its lead id).
     */
    public function sendText(Conversation $conversation, string $body, ?string $replyToProviderId = null): SendResult;

    /**
     * Send an attachment (image/audio/video/document/sticker).
     *
     * The file already lives on the `public` disk - the driver reads it from
     * there and does whatever its provider needs (Cloud API uploads it first and
     * addresses the result by media id).
     *
     * Drivers that cannot send media inherit the throwing default from
     * BaseGateway; declaring the type in capabilities() is what makes it
     * reachable from the composer.
     *
     * @throws \Webkul\Whatsapp\Exceptions\UnsupportedMessageException
     */
    public function sendMedia(Conversation $conversation, OutboundMedia $media, ?string $replyToProviderId = null): SendResult;

    /**
     * Send a location pin.
     *
     * @throws \Webkul\Whatsapp\Exceptions\UnsupportedMessageException
     */
    public function sendLocation(Conversation $conversation, OutboundLocation $location, ?string $replyToProviderId = null): SendResult;

    /**
     * Send interactive reply buttons or an option list.
     *
     * Unlike a template, this needs no prior approval - but it only works
     * inside the 24h window, same as any free-form message.
     */
    public function sendInteractive(Conversation $conversation, OutboundInteractive $interactive, ?string $replyToProviderId = null): SendResult;

    /**
     * Send an approved template (HSM).
     *
     * This is the only message a WhatsApp channel accepts once the 24h service
     * window has closed. Channels without a window (Messenger) never need it,
     * and channels that proxy someone else's inbox (Kommo) cannot address Meta's
     * template catalogue directly - both inherit the throwing default from
     * BaseGateway.
     *
     * @throws \Webkul\Whatsapp\Exceptions\UnsupportedMessageException
     */
    public function sendTemplate(Conversation $conversation, OutboundTemplate $template, ?string $replyToProviderId = null): SendResult;
}
