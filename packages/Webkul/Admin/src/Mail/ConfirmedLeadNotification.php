<?php

namespace Webkul\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ConfirmedLeadNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  \Webkul\Lead\Models\Lead  $lead
     * @param  array  $details
     * @return void
     */
    public function __construct(
        public $lead,
        public $details
    ) {}

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        /*
         * core()->getSenderEmailDetails() NO existe en Webkul\Core\Core, así que
         * este correo venía muriendo con "Call to undefined method" en cada
         * intento: el almacén nunca recibió un aviso. El remitente sale de
         * config/mail.php, que es el que el CRM tiene configurado.
         */
        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->subject(trans('admin::app.emails.leads.confirmed.subject', ['id' => $this->lead->id]))
            ->view('admin::emails.leads.confirmed');
    }
}
