<?php

namespace Webkul\Whatsapp\Gateways\Contracts;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webkul\Whatsapp\Gateways\Dto\Capabilities;
use Webkul\Whatsapp\Gateways\Dto\InboundBatch;
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
}
