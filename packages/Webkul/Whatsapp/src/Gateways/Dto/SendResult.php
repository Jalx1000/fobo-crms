<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * Result of an outbound send, normalized across providers.
 */
final class SendResult
{
    /**
     * @param  string  $providerMessageId  Provider's message id. Providers that
     *                                     don't return one (e.g. Kommo) must
     *                                     synthesize a stable value so the
     *                                     idempotency guarantee still holds.
     */
    public function __construct(public string $providerMessageId) {}
}
