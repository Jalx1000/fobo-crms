<?php

namespace Webkul\Whatsapp\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Webkul\Whatsapp\Models\Message;

class MensajeFallido extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Message $mensaje,
        public string $motivo,
        public string $inboxUrl,
    ) {}

    public function build()
    {
        $cliente = $this->mensaje->conversation?->wa_name ?: $this->mensaje->conversation?->wa_phone;

        return $this->subject('WhatsApp: no le llegó un mensaje a '.($cliente ?: 'un cliente'))
            ->view('whatsapp::emails.fallo.mensaje');
    }
}
