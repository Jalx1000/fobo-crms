<?php

namespace Webkul\Whatsapp\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Webkul\Whatsapp\Models\Conversation;

class HandoffRequested extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  bool  $assigned  True when it went to a named advisor, false when
     *                          it landed in the team pool (nobody owns it yet).
     */
    public function __construct(
        public Conversation $conversation,
        public bool $assigned = false,
    ) {}

    public function build()
    {
        // No explicit ->from(): config('mail.from') already holds it. The one
        // other mailable in the project calls core()->getSenderEmailDetails(),
        // which does not exist on this Krayin version and throws on send.
        return $this->subject($this->assigned
                ? 'Te derivaron una conversación de WhatsApp'
                : 'Conversación de WhatsApp sin asesor')
            ->markdown('whatsapp::emails.handoff.requested', [
                'conversation' => $this->conversation,
                'assigned'     => $this->assigned,
                'contactName'  => $this->conversation->person->name
                    ?? $this->conversation->wa_name
                    ?? $this->conversation->wa_phone
                    ?? 'Contacto sin nombre',
                'messages'     => $this->conversation->messages()
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->sortBy('id')
                    ->values(),
                // Deep link straight into the conversation; chat.blade.php reads
                // ?conversation= on mount.
                'url'          => route('admin.whatsapp.index').'?conversation='.$this->conversation->id,
            ]);
    }
}
