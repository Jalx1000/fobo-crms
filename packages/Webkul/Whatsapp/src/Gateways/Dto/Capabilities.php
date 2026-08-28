<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * What a driver ACTUALLY implements today — not what the provider supports in
 * theory. The inbox composer renders from this, so a listed capability must
 * always work.
 */
final class Capabilities
{
    /**
     * @param  array<int, string>  $send  e.g. ['text', 'image', 'document']
     * @param  array<int, string>  $receive
     */
    public function __construct(
        public array $send = [],
        public array $receive = [],
    ) {}

    public function canSend(string $type): bool
    {
        return in_array($type, $this->send, true);
    }

    public function toArray(): array
    {
        return ['send' => $this->send, 'receive' => $this->receive];
    }
}
