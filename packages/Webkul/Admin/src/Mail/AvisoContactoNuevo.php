<?php

namespace Webkul\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso interno a un asesor: se registró un contacto que le toca (asignado a
 * él, de su ciudad, o sin ciudad para la cobertura nacional).
 */
class AvisoContactoNuevo extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $person,
        public $asesor,
        public ?string $ciudad = null,
    ) {}

    public function build()
    {
        return $this->subject('Nuevo contacto: '.$this->person->name.($this->ciudad ? " ({$this->ciudad})" : ' (sin ciudad)'))
            ->view('admin::emails.contacto-nuevo.aviso');
    }
}
