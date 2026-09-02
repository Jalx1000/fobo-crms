<?php

namespace Webkul\Whatsapp\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Webkul\Whatsapp\Models\Handoff;

/**
 * Aviso al supervisor: una derivación lleva demasiado sin que nadie la tome.
 */
class HandoffSinTomar extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Handoff $handoff) {}

    public function build(): self
    {
        $minutos = $this->handoff->opened_at->diffInMinutes(now());

        return $this
            ->subject("Derivación sin atender hace {$minutos} min — ".($this->handoff->conversation?->wa_name ?: 'cliente'))
            ->markdown('whatsapp::emails.handoff.sin-tomar', [
                'handoff' => $this->handoff,
                'minutos' => $minutos,
            ]);
    }
}
