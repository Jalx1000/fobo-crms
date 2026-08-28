<?php

namespace Webkul\Whatsapp\Gateways\Contracts;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\DownloadedMedia;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
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
     * Send one media file (photo, video or document).
     *
     * Drivers that cannot do this must throw UnsupportedCapability rather than
     * degrade to a text message with a link: the caller needs to know the file
     * did not arrive, and a silent downgrade reads as success.
     *
     * @throws \Webkul\Whatsapp\Gateways\UnsupportedCapability
     */
    public function sendMedia(Conversation $conversation, OutboundMedia $media): SendResult;

    /**
     * Send a geographic location.
     *
     * @throws \Webkul\Whatsapp\Gateways\UnsupportedCapability
     */
    public function sendLocation(Conversation $conversation, OutboundLocation $location): SendResult;

    /**
     * Upload a local file to the provider and return its media id, or null if
     * the provider has no media store (then the driver sends by URL).
     *
     * Called when the asesor uploads the file, never at send time: a provider
     * that has to fetch the image from this server mid-conversation turns a
     * slow CRM into a message the customer never receives.
     */
    public function uploadMedia(string $absolutePath, string $mimeType): ?string;

    /**
     * Download an inbound file by the provider's media id.
     *
     * Returns null when the provider reports the file is gone; throws
     * UnsupportedCapability when the driver cannot receive files at all — the
     * two cases mean different things to the caller and must not be conflated.
     *
     * Implementations must resolve the id on every call and never cache a
     * download URL: providers hand out short-lived URLs, so a cached one is
     * already dead by the time a retry uses it.
     *
     * @throws \Webkul\Whatsapp\Gateways\UnsupportedCapability
     */
    public function downloadMedia(string $mediaId, int $maxBytes): ?DownloadedMedia;
}
