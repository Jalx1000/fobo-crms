<?php

namespace Webkul\Whatsapp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Pone al usuario del token en el guard "user" para la request de la app.
 *
 * El módulo entero —el bouncer, "asignadas a mí", el autor de un mensaje, el
 * actor de una derivación— lee `auth()->guard('user')`. Con un token Sanctum
 * ese guard está vacío: el bouncer contesta que no hay permiso y todo daría
 * 403. Cargarlo acá deja reutilizar la bandeja tal cual, sin copiar su SQL.
 *
 * setUser() no escribe sesión ni cookie: el usuario vive sólo en esta request.
 * Va DESPUÉS de auth:sanctum, que ya validó el token.
 */
class AuthenticateMobileUser
{
    public function handle(Request $request, Closure $next)
    {
        Auth::guard('user')->setUser($request->user('sanctum'));

        return $next($request);
    }
}
