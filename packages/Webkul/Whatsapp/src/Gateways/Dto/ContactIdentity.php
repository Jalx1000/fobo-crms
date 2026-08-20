<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * Who sent an inbound message. Providers differ: Cloud API gives a phone,
 * Kommo gives its own contact id (the phone needs a second API call), Messenger
 * gives a page-scoped id and never a phone at all.
 */
final class ContactIdentity
{
    public function __construct(
        public ?string $phone = null,
        public ?string $providerId = null,
        public ?string $name = null,
        // Profile picture, already stored on our side. Channels that expose one
        // (Messenger) fill it in; it is the only avatar the inbox ever shows.
        public ?string $avatarUrl = null,
    ) {}

    /**
     * Whether there is anything at all to key a contact on.
     */
    public function isEmpty(): bool
    {
        return ! $this->phone && ! $this->providerId;
    }
}
