<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * A delivery receipt for an outbound message, mapped to our canonical statuses
 * (sent | delivered | read | failed).
 */
final class StatusUpdate
{
    /**
     * @param  ?string  $error  Por qué falló, tal como lo explica el proveedor
     *                          ("131049 · This message was not delivered to
     *                          maintain healthy ecosystem engagement"). Sólo en
     *                          `failed`. Sin esto, 14 plantillas fallaron y el
     *                          CRM no podía decir por qué.
     */
    public function __construct(
        public string $providerMessageId,
        public string $status,
        public ?string $error = null,
    ) {}
}
