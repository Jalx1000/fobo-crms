<?php

namespace Webkul\Admin\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Admin\Exceptions\Handler;
use Webkul\Admin\Http\Middleware\Bouncer as BouncerMiddleware;
use Webkul\Admin\Http\Middleware\Locale;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(Router $router): void
    {
        $router->aliasMiddleware('user', BouncerMiddleware::class);

        $router->aliasMiddleware('admin_locale', Locale::class);

        include __DIR__.'/../Http/helpers.php';

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/Admin/web.php');

        Route::middleware(['web', 'admin_locale'])
            ->group(__DIR__.'/../Routes/Front/web.php');

        /*
        | API REST de leads con atributos personalizados (LeadApiController).
        | En booted(): tiene que quedar DESPUÉS de las rutas de krayin/rest-api
        | para reemplazar sus GET/PUT /api/v1/leads/{id} y POST /api/v1/leads
        | (una ruta registrada más tarde con el mismo método y URI gana).
        */
        $this->app->booted(function () {
            Route::middleware(['api', 'auth:sanctum'])
                ->prefix('api/v1')
                ->group(function () {
                    Route::get('leads/{id}', [\Webkul\Admin\Http\Controllers\Api\LeadApiController::class, 'show'])->whereNumber('id');
                    Route::put('leads/{id}', [\Webkul\Admin\Http\Controllers\Api\LeadApiController::class, 'update'])->whereNumber('id');
                    Route::post('leads', [\Webkul\Admin\Http\Controllers\Api\LeadApiController::class, 'store']);
                });
        });

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'admin');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'admin');

        Blade::anonymousComponentPath(__DIR__.'/../Resources/views/components', 'admin');

        $this->app->bind(ExceptionHandler::class, Handler::class);

        Relation::morphMap([
            'leads'         => \Webkul\Lead\Models\Lead::class,
            'organizations' => \Webkul\Contact\Models\Organization::class,
            'persons'       => \Webkul\Contact\Models\Person::class,
            'products'      => \Webkul\Product\Models\Product::class,
            'quotes'        => \Webkul\Quote\Models\Quote::class,
            'warehouses'    => \Webkul\Warehouse\Models\Warehouse::class,
        ]);

        $this->app->register(EventServiceProvider::class);
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerFacades();

        $this->registerConfig();
    }

    /**
     * Register Bouncer as a singleton.
     */
    protected function registerFacades(): void
    {
        $loader = AliasLoader::getInstance();

        $loader->alias('Bouncer', \Webkul\Admin\Facades\Bouncer::class);

        $this->app->singleton('bouncer', function () {
            return new \Webkul\Admin\Bouncer;
        });
    }

    /**
     * Register package config.
     */
    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/acl.php', 'acl');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/core_config.php', 'core_config');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/attribute_lookups.php', 'attribute_lookups');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/attribute_entity_types.php', 'attribute_entity_types');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/reporting.php', 'reporting');
    }
}
