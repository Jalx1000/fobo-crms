<?php

namespace Webkul\Inmobiliaria\Listeners;

use Illuminate\View\View;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;

/**
 * Alimenta la pestaña "Inmuebles" de la ficha del lead.
 *
 * Es un view composer y no un listener de render: la pestaña se declara en la
 * vista del lead, que ya incluye la plantilla, así que lo único que falta es
 * ponerle los datos. Antes esto se inyectaba como bloque suelto en la columna
 * izquierda y quedaba fuera de lugar entre los atributos y la persona.
 */
class ComponerInmueblesDelLead
{
    public function __construct(protected InmuebleRepository $inmuebles) {}

    public function compose(View $view): void
    {
        $lead = $view->getData()['lead'] ?? null;

        $view->with('inmuebles', $lead
            ? $this->inmuebles->deLead($lead->id)
            : collect());
    }
}
