<?php

namespace Webkul\Whatsapp\Exceptions;

use RuntimeException;

/**
 * Someone else got there first: the conversation was claimed (or its handoff
 * closed) between the moment the advisor saw it in the list and the moment they
 * pressed the button. Controllers map this to a 409.
 */
class HandoffConflictException extends RuntimeException
{
    public static function alreadyClaimed(): self
    {
        return new self('Otro asesor tomó esta conversación.');
    }

    public static function notOpen(): self
    {
        return new self('Esta conversación no tiene una derivación abierta.');
    }
}
