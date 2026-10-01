<?php

namespace Webkul\Distribuidor\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Distribuidor\Http\Middleware\EnsureDistribuidorAccess;

class DistribuidorServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'distribuidor');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->app['router']->aliasMiddleware('distribuidor_access', EnsureDistribuidorAccess::class);

        /*
         * 'user' sólo verifica que haya sesión; el permiso del módulo lo exige
         * distribuidor_access. Sin él, cualquier usuario autenticado podría
         * crear pedidos y registrar cobros desde el Punto.
         */
        Route::middleware(['web', 'admin_locale', 'user', 'distribuidor_access'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/admin.php');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/distribuidor.php', 'distribuidor');
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/acl.php', 'acl');
    }
}
