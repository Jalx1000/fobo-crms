<?php

namespace Webkul\Distribuidor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Exige el permiso "distribuidor" para todo el módulo.
 *
 * Ocultar el ítem del menú no alcanza: las rutas del Punto crean pedidos,
 * contactos y cobros. Sin esto, cualquier usuario con sesión podría postear a
 * /distribuidor/pedido y registrar una venta a nombre de otro.
 */
class EnsureDistribuidorAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! bouncer()->hasPermission('distribuidor')) {
            // El Punto llama por axios y no navega: un redirect le llegaría como
            // HTML dentro de un .then() y se vería como error de parseo.
            if ($request->expectsJson()) {
                return response()->json(['message' => 'No tenés acceso al Punto de Distribuidor.'], 403);
            }

            abort(403, 'No tenés acceso al Punto de Distribuidor.');
        }

        return $next($request);
    }
}
