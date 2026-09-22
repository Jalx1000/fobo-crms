<?php

namespace Webkul\Whatsapp\Exceptions;

use RuntimeException;

/**
 * The active gateway cannot send this kind of message.
 *
 * Exists so the honest answer travels all the way to the caller. The tempting
 * alternative — swallowing it and answering 200 — is the most expensive bug
 * this module can ship: the AI agent believes it spoke to the customer and
 * goes quiet, and nothing anywhere reports a failure.
 */
class CapabilityNotSupportedException extends RuntimeException
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $capability,
    ) {
        parent::__construct(sprintf(
            'El canal activo (%s) no puede enviar mensajes de tipo "%s".',
            $gateway,
            $capability,
        ));
    }

    /**
     * 501 Not Implemented: the request was well formed, the server just does
     * not have this feature. A 4xx would blame the caller for our limitation.
     */
    public function status(): int
    {
        return 501;
    }
}
