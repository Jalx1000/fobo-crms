<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * A delivery receipt for an outbound message, mapped to our canonical statuses
 * (sent | delivered | read | failed).
 */
final class StatusUpdate
{
    /**
     * @param  string|null  $error  Why the provider could not deliver it, in the
     *                              provider's own words. Only ever set on a
     *                              'failed' receipt.
     *
     *                              Carried because a message that Meta ACCEPTED
     *                              (it returned a wamid) and then failed to
     *                              deliver has its reason only here — dropping
     *                              it leaves a row marked failed with nothing to
     *                              act on, and the agent staring at a blank.
     */
    public function __construct(
        public string $providerMessageId,
        public string $status,
        public ?string $error = null,
    ) {}
}
