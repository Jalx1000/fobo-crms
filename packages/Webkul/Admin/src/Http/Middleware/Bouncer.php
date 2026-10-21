<?php

namespace Webkul\Admin\Http\Middleware;

use Illuminate\Support\Facades\Route;

class Bouncer
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, \Closure $next, $guard = 'user')
    {
        if (! auth()->guard($guard)->check()) {
            return $this->alLogin($request);
        }

        /**
         * If user status is changed by admin. Then session should be
         * logged out.
         */
        if (! (bool) auth()->guard($guard)->user()->status) {
            auth()->guard($guard)->logout();

            session()->flash('error', __('admin::app.errors.401'));

            return $this->alLogin($request);
        }

        /**
         * If somehow the user deleted all permissions, then it should be
         * auto logged out and need to contact the administrator again.
         */
        if ($this->isPermissionsEmpty()) {
            auth()->guard($guard)->logout();

            session()->flash('error', __('admin::app.errors.401'));

            return $this->alLogin($request);
        }

        return $next($request);
    }

    /**
     * Sin sesión: la página va al login; un pedido AJAX recibe un 401 marcado.
     *
     * Al AJAX no se le puede redirigir: axios sigue el 302 en silencio, recibe el
     * HTML del login con 200 y la pantalla queda "colgada" sin decir nada. El
     * encabezado lo distingue del 401 de "no tenés permiso", que también existe y
     * no debe sacar a nadie de la página (ver plugins/axios.js).
     */
    protected function alLogin($request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => trans('admin::app.common.unauthenticated')], 401)
                ->header('X-Sesion-Vencida', '1');
        }

        return redirect()->route('admin.session.create');
    }

    /**
     * Check for user, if they have empty permissions or not except admin.
     *
     * @return bool
     */
    public function isPermissionsEmpty()
    {
        if (! $role = auth()->guard('user')->user()->role) {
            abort(401, 'Esta acción no está autorizada.');
        }

        if ($role->permission_type === 'all') {
            return false;
        }

        if ($role->permission_type !== 'all' && empty($role->permissions)) {
            return true;
        }

        $this->checkIfAuthorized();

        return false;
    }

    /**
     * Check authorization.
     *
     * @return null
     */
    public function checkIfAuthorized()
    {
        $roles = acl()->getRoles();

        if (isset($roles[Route::currentRouteName()])) {
            $permissionKey = $roles[Route::currentRouteName()];

            // Fallback directo en el middleware para actividades y etiquetas
            if (str_contains($permissionKey, 'activities') || str_contains($permissionKey, 'tags')) {
                $user = auth()->guard('user')->user();
                if ($user->role->permission_type == 'all') {
                    return;
                }
                
                $rolePermissions = $user->role->permissions ?? [];
                foreach ($rolePermissions as $p) {
                    if (str_starts_with($p, 'leads')) {
                        return;
                    }
                }
            }

            bouncer()->allow($permissionKey);
        }
    }
}
