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
            return redirect()->route('admin.session.create');
        }

        /**
         * If user status is changed by admin. Then session should be
         * logged out.
         */
        if (! (bool) auth()->guard($guard)->user()->status) {
            auth()->guard($guard)->logout();

            session()->flash('error', __('admin::app.errors.401'));

            return redirect()->route('admin.session.create');
        }

        /**
         * If somehow the user deleted all permissions, then it should be
         * auto logged out and need to contact the administrator again.
         */
        if ($this->isPermissionsEmpty()) {
            auth()->guard($guard)->logout();

            session()->flash('error', __('admin::app.errors.401'));

            return redirect()->route('admin.session.create');
        }

        return $next($request);
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
