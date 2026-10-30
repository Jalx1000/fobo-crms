<?php

namespace Webkul\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de consumo diario de la API de SMD: "warning" (300) o "danger" (500,
 * en rojo). Va al personal de OdontoKing y Sofopolis (smd.api_budget.alerts).
 */
class SmdUsageAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, int>  $sources  "origen → endpoint" => consultas
     */
    public function __construct(
        public string $level,
        public string $date,
        public int $count,
        public int $threshold,
        public int $month,
        public array $sources = [],
    ) {}

    public function build(): self
    {
        $subject = $this->level === 'danger'
            ? "🔴 PELIGRO: {$this->count} consultas a SMD hoy ({$this->date})"
            : "⚠️ Aviso: {$this->count} consultas a SMD hoy ({$this->date})";

        return $this->subject($subject)->view('admin::emails.smd-usage-alert');
    }
}
