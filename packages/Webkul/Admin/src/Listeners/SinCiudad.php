<?php

namespace Webkul\Admin\Listeners;

use Webkul\Whatsapp\Jobs\AsignarSinCiudad;

/**
 * Un pedido que nace en «Sin ciudad» se reasigna a la hora al asesor nacional
 * de su producto (spec docs/specs/asignar-sin-ciudad.md).
 */
class SinCiudad
{
    public function agendar($lead): void
    {
        if ($lead?->id && $lead->lead_pipeline_id) {
            AsignarSinCiudad::agendar((int) $lead->id, (int) $lead->lead_pipeline_id);
        }
    }
}
