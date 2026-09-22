<?php

namespace Webkul\Whatsapp\Exceptions;

use RuntimeException;

class HandoffConflictException extends RuntimeException
{
    public static function alreadyClaimed(): self
    {
        return new self('Otro asesor tomó esta conversación primero.');
    }

    public static function notOpen(): self
    {
        return new self('Esta conversación no tiene una derivación abierta.');
    }

    public function status(): int
    {
        return 409;
    }
}
