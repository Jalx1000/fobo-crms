<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * One webhook delivery may carry many events (Cloud API batches them) or a
 * single one (Kommo). The batch normalizes both shapes.
 */
final class InboundBatch
{
    /**
     * @param  array<int, InboundMessage>  $messages
     * @param  array<int, StatusUpdate>  $statuses
     */
    public function __construct(
        public array $messages = [],
        public array $statuses = [],
    ) {}
}
