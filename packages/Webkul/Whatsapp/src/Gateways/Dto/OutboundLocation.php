<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * Una ubicación a enviar.
 *
 * Las coordenadas llegan hasta acá desde la base sin haber pasado nunca por el
 * modelo de lenguaje: el agente pide "mandá la ubicación del C21-0002" y el CRM
 * resuelve el resto.
 */
final class OutboundLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $name = null,
        public ?string $address = null,
    ) {}
}
