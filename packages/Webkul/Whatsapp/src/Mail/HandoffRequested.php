<?php

namespace Webkul\Whatsapp\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Webkul\Whatsapp\Models\Conversation;

class HandoffRequested extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public ?string $advisorName,
        public ?string $cityName,
        public string $inboxUrl,
    ) {}

    public function build()
    {
        $quien = $this->advisorName ?: 'el equipo';

        return $this->subject('WhatsApp: conversación derivada a '.$quien)
            ->view('whatsapp::emails.handoff.requested');
    }
}
