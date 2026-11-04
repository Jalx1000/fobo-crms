<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * An inbound message, normalized. Drivers guarantee providerMessageId - if the
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
        // Media (image/audio/video/document/sticker): the provider's opaque media
        // id to fetch the bytes with, plus what we know about the file up front.
        public ?string $mediaId = null,
        public ?string $mediaMime = null,
        public ?string $mediaFilename = null,
        // Which of OUR accounts received this, when a channel can serve several
        // (a Messenger page, a WhatsApp number). Persisted on the conversation
        // so the reply goes out through the same account it arrived on.
        public ?string $providerAccountId = null,
        // 'inbound' from the contact, 'outbound' for an echo of something the
        // team sent from the provider's own app. An echo is history, not a new
        // message: it never reopens the window, marks unread, or wakes the AI.
        public string $direction = 'inbound',
    ) {}

    public function isEcho(): bool
    {
        return $this->direction === 'outbound';
    }
}
