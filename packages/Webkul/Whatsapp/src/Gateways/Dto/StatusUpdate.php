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
        /**
         * Por qué falló, tal como lo manda Meta (`code`, `title`, `message`,
         * `error_data.details`). Vacío salvo en `failed`.
         *
         * @var array<int, array<string, mixed>>
         */
        public array $errors = [],
    ) {}
}
