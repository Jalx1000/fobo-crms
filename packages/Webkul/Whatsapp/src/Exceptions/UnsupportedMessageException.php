<?php

namespace Webkul\Whatsapp\Exceptions;

use RuntimeException;

/**
 * The active gateway cannot deliver this kind of message. The composer renders
 * from Capabilities and should never offer it, so reaching this means the UI and
 * the driver drifted apart - or something posted straight to the endpoint.
 */
class UnsupportedMessageException extends RuntimeException
{
    public static function type(string $gateway, string $type): self
    {
        return new self(sprintf('El canal [%s] no puede enviar mensajes de tipo [%s].', $gateway, $type));
    }
}
