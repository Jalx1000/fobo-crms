<?php

namespace Webkul\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Error crítico de la integración con SMD o Dropbox (A-1 a A-7 de
 * planning/16). Lo manda SmdAlerts, uno por tipo por hora, a SMD_ALERT_EMAILS.
 */
class SmdIntegrationAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $check,
        public string $since,
        public int $count,
        public array $context = [],
    ) {}

    public function build(): self
    {
        return $this->subject("🔴 Sofo CRM · {$this->type}: {$this->title}")
            ->view('admin::emails.smd-integration-alert');
    }
}
