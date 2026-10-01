<?php

namespace Webkul\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Bienvenida al cliente recién registrado. Sale con el remitente de
 * config('mail.from') (MAIL_FROM_ADDRESS).
 */
class BienvenidaContacto extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $person) {}

    public function build()
    {
        return $this->subject('¡Bienvenido a '.config('app.name').'!')
            ->view('admin::emails.contacto-nuevo.bienvenida');
    }
}
