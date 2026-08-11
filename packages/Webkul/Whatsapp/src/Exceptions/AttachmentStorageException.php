<?php

namespace Webkul\Whatsapp\Exceptions;

use RuntimeException;

/**
 * The attachment could not be written to the `public` disk.
 *
 * In practice this is always a permissions problem: PHP-FPM and the queue
 * worker often run as different users, and whichever one created
 * storage/app/public/whatsapp/media/{conversation}/ owns it. If the web process
 * is not the owner, every outbound upload fails while inbound media keeps
 * working — which makes the symptom look like a WhatsApp problem instead of a
 * filesystem one.
 */
class AttachmentStorageException extends RuntimeException
{
    public static function couldNotWrite(string $path): self
    {
        return new self(sprintf('No se pudo guardar el adjunto en el servidor [%s].', $path));
    }
}
