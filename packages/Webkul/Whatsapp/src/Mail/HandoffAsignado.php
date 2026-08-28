<?php

namespace Webkul\Whatsapp\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Webkul\Whatsapp\Models\Handoff;

class HandoffAsignado extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Handoff $handoff) {}

    public function build()
    {
        $conversation = $this->handoff->conversation;

        // Sin ->from() explícito: config('mail.from') ya lo tiene.
        return $this->subject('Te derivaron una conversación de WhatsApp')
            ->markdown('whatsapp::emails.handoff.asignado', [
                'handoff'  => $this->handoff,
                'contacto' => $conversation?->person?->name
                    ?? $conversation?->wa_name
                    ?? $conversation?->wa_phone
                    ?? 'Contacto sin nombre',
                'mensajes' => $conversation
                    ? $conversation->messages()->latest('id')->limit(5)->get()->sortBy('id')->values()
                    : collect(),
                // Link directo a la conversación: chat.blade.php lee ?conversation=
                'url'      => route('admin.whatsapp.index').'?conversation='.$this->handoff->conversation_id,
            ]);
    }
}
