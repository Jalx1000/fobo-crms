<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * An inbound message, normalized. Drivers guarantee providerMessageId — if the
 * provider gives none, the driver synthesizes a stable one so idempotency holds.
 */
final class InboundMessage
{
    public function __construct(
        public string $providerMessageId,
        public ContactIdentity $contact,
        public string $type = 'text',
        public ?string $body = null,
        public ?string $providerConversationId = null,
        public ?string $replyToProviderId = null,
        public array $raw = [],
        /*
         * False when the message reached a business number we no longer send
         * from (an old number still subscribed to the app). It is shown in the
         * thread, but it does not open the 24h window: Meta counts the window
         * per business number, and replies leave from the active one.
         */
        public bool $opensWindow = true,
    ) {}
}
