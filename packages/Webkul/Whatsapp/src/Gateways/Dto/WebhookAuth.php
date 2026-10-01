<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * Outcome of authenticating an inbound webhook. Carries a reason so a rejection
 * can be diagnosed — a bare bool tells you nothing at 3am.
 */
final class WebhookAuth
{
    private function __construct(
        public bool $ok,
        public ?string $reason = null,
    ) {}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
