<?php

namespace Webkul\Whatsapp\Gateways;

use RuntimeException;

/**
 * El gateway activo no implementa lo que se le pidió.
 *
 * Existe para que la respuesta al agente sea un 501 explícito en vez de un 200
 * mentiroso o un 500 genérico: "este canal no manda fotos" es información
 * accionable, "error interno" no lo es.
 */
class UnsupportedCapability extends RuntimeException
{
    public static function para(string $gateway, string $capacidad): self
    {
        return new self("El gateway '{$gateway}' no soporta enviar {$capacidad}.");
    }
}
