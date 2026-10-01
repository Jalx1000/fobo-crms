<?php

namespace Webkul\Whatsapp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Exige el permiso "whatsapp" para todo el módulo.
 *
 * Ocultar la pestaña no alcanza: sin esto, cualquier usuario autenticado —de
 * cualquier rol— podía pedir /whatsapp/thread y leer la conversación completa
 * de un cliente, o postear a /whatsapp/send y escribirle en nombre de la
 * empresa. El menú lateral ya respetaba el ACL, así que el módulo se veía
 * restringido mientras sus rutas no lo estaban.
 */
class EnsureWhatsappAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! bouncer()->hasPermission('whatsapp')) {
            // JSON para el inbox, que llama por axios y no navega: un redirect
            // le llegaría como HTML dentro de un .then() y se vería como un
            // error de parseo en vez de "no tenés permiso".
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'No tenés acceso al módulo de WhatsApp.',
                ], 403);
            }

            abort(403, 'No tenés acceso al módulo de WhatsApp.');
        }

        return $next($request);
    }
}
