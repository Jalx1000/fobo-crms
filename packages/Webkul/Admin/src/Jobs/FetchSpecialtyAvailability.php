<?php

namespace Webkul\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Admin\Services\BookingOptions;

/**
 * Pide a SMD los horarios de una especialidad cuando un paciente la elige en
 * el formulario de WhatsApp (1 consulta). En segundo plano porque SMD puede
 * tardar más de lo que Meta espera una pantalla; el formulario la espera y,
 * si no llega a tiempo, la toma ya guardada en el siguiente toque.
 *
 * Una sola vez: si falla, BookingOptions guarda la falla y el formulario
 * deriva a recepción. Nunca se reintenta en bucle contra SMD.
 */
class FetchSpecialtyAvailability implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $specialtyId, public string $from, public int $days) {}

    public function handle(BookingOptions $booking): void
    {
        $booking->fetch($this->specialtyId, $this->from, $this->days);
    }
}
