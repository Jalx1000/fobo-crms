<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * A delivery receipt for an outbound message, mapped to our canonical statuses
 * (sent | delivered | read | failed).
 */
final class StatusUpdate
{
    public function __construct(
        public string $providerMessageId,
        public string $status,
    ) {}
}
