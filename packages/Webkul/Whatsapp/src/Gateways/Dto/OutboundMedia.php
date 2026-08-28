<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * Un archivo a enviar, ya resuelto por el CRM.
 *
 * El driver recibe esto y no necesita saber nada del catálogo: se le dice qué
 * mandar, no de dónde sacarlo. Trae las dos rutas posibles porque cuál se usa
 * es decisión del driver: si el proveedor cachea media (Cloud API), prefiere
 * `providerMediaId`; si no, cae a `url`.
 */
final class OutboundMedia
{
    /**
     * @param  string  $kind  image | video | document
     */
    public function __construct(
        public string $kind,
        public ?string $providerMediaId = null,
        public ?string $url = null,
        public ?string $caption = null,
        public ?string $filename = null,
    ) {}

    public function tieneDestino(): bool
    {
        return filled($this->providerMediaId) || filled($this->url);
    }
}
