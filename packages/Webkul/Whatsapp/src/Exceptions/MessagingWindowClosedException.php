<?php

namespace Webkul\Whatsapp\Exceptions;

use RuntimeException;

/**
 * The 24h service window is closed and no approved template was supplied, so
 * there is no legal way to deliver this message.
 *
 * Thrown before anything is written: queueing a free-form message here would
 * only surface minutes later as a failed row with Meta's 131047, long after the
 * person who pressed send stopped looking.
 */
class MessagingWindowClosedException extends RuntimeException
{
    public static function noTemplate(int $conversationId): self
    {
        return new self(
            "La ventana de 24h de la conversación #{$conversationId} está cerrada y no se pasó ninguna plantilla aprobada."
        );
    }
}
