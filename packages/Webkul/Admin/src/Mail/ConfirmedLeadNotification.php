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
        return $this->from(core()->getSenderEmailDetails()['email'], core()->getSenderEmailDetails()['name'])
            ->subject(trans('admin::app.emails.leads.confirmed.subject', ['id' => $this->lead->id]))
            ->view('admin::emails.leads.confirmed');
    }
}
