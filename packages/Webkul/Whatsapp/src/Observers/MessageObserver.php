<?php

namespace Webkul\Whatsapp\Observers;

use Throwable;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\IntervencionAsesor;

/**
 * Un solo lugar para lo que pasa al guardarse un mensaje, en vez de repetirlo
 * en cada uno de los caminos que lo crean (inbox, documentos, Productos, ecos
 * de Kommo / WhatsApp Business / Messenger). Un canal nuevo queda cubierto solo.
 */
class MessageObserver
{
    public function created(Message $message): void
    {
        // Mover la etapa nunca puede impedir que el mensaje quede guardado.
        try {
            app(IntervencionAsesor::class)->registrar($message);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
