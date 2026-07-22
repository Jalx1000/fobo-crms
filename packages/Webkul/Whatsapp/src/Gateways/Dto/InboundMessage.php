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
        // Attachment the customer sent: a URL the ingestion job downloads into
        // our own storage. Providers hand these as short-lived links (Kommo's
        // amojo URLs expire), so the link is only good at ingestion time.
        public ?string $mediaLink = null,
        public ?string $mediaName = null,
        public ?string $mediaMime = null,
    ) {}
}
