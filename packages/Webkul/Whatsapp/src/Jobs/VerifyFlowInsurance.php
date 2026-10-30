<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Admin\Services\InsuranceService;

/**
 * Verifica el seguro declarado en el formulario de agendar apenas el paciente
 * lo elige, mientras sigue eligiendo especialidad, doctor y horario: así el
 * resumen ya puede mostrar si tiene cobertura antes de confirmar. Alianza
 * tarda de 11 a 55 s, más de lo que Meta espera una pantalla.
 *
 * Solo consulta (InsuranceService::verifyCi deja el resultado en caché); a la
 * ficha lo escribe BookFlowAppointment al agendar.
 */
class VerifyFlowInsurance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /** @param  array<int, int>|null  $optionIds  null = todas las aseguradoras ("No sé") */
    public function __construct(public string $ci, public ?array $optionIds = null) {}

    public function handle(InsuranceService $insurance): void
    {
        $insurance->verifyCi($this->ci, $this->optionIds);
    }
}
