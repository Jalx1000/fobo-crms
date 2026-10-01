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
    ) {}
}
