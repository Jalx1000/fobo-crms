<?php

namespace Webkul\Inmobiliaria\Listeners;

use Illuminate\Support\Facades\View;
use Webkul\Core\ViewRenderEventManager;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;

/**
 * Inyecta la sección "Inmuebles de interés" en la ficha del lead.
 *
 * Se engancha al evento de render que el core ya expone en vez de editar
 * `admin::leads.view`: un upgrade de Krayin pisaría el archivo, y el punto de
 * extensión existe justamente para esto.
 */
class InmueblesDelLead
{
    public function __construct(protected InmuebleRepository $inmuebles) {}

    public function handle(ViewRenderEventManager $evento): void
    {
        $lead = $evento->getParam('lead');

        if (! $lead) {
            return;
        }

        $inmuebles = $this->inmuebles->deLead($lead->id);

        // Sin interés registrado no se dibuja nada: una sección vacía en cada
        // lead del CRM sería ruido permanente para los que no son inmobiliarios.
        if ($inmuebles->isEmpty()) {
            return;
        }

        /*
        | Se agrega el HTML ya renderizado, no el nombre de la vista.
        |
        | `addTemplate()` solo acepta un nombre y el manager le pasa a la vista
        | los parámetros del evento —acá, únicamente el lead—, así que no hay
        | forma de inyectarle los inmuebles por esa vía. Su `render()` concatena
        | cualquier string que no sea una vista existente, y eso permite
        | resolver la consulta una sola vez acá en lugar de repetirla dentro
        | del blade.
        */
        $evento->addTemplate(
            View::make('inmobiliaria::leads.inmuebles-interes', compact('inmuebles'))->render()
        );
    }
}
