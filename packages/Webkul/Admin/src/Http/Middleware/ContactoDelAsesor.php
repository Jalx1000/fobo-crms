<?php

namespace Webkul\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\Alcance;

/**
 * Un ejecutivo solo abre y toca SUS contactos (spec
 * docs/specs/tablero-por-asesor.md, D13b). Los ejecutivos tienen permiso
 * individual para no pelearse por los contactos: la lista ya filtraba, pero la
 * ficha, la edición y las rutas `persons/{id}/…` aceptaban cualquier id.
 *
 * Va en el grupo de rutas de personas, así una ruta nueva con `{id}` queda
 * cubierta sin acordarse. 404 y no 403: no confirma que el contacto existe.
 */
class ContactoDelAsesor
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->route('id');
        $userId = app(Alcance::class)->userId();

        if ($id !== null && $userId !== null) {
            abort_unless(
                DB::table('persons')->where('id', $id)->where('user_id', $userId)->exists(),
                404
            );
        }

        return $next($request);
    }
}
