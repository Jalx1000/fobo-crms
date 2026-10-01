<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * Who sent an inbound message. Providers differ: Cloud API gives a phone,
 * Kommo gives its own contact id (the phone needs a second API call).
 */
final class ContactIdentity
{
    public function __construct(
        public ?string $phone = null,
        public ?string $providerId = null,
        public ?string $name = null,
    ) {}
}
