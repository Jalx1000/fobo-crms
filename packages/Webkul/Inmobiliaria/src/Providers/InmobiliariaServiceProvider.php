<?php

namespace Webkul\Inmobiliaria\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Inmobiliaria\Console\Commands\RollbackInmobiliaria;
use Webkul\Inmobiliaria\Console\Commands\SeedInmuebles;
use Webkul\Inmobiliaria\Console\Commands\SetupInmobiliaria;

/**
 * Operación inmobiliaria de esta instancia.
 *
 * Dos mitades bien distintas:
 *
 *  - **Datos maestros** (pipelines, atributos, sources, types): se aplican por
 *    comando, no por migración, porque son configuración del negocio y tienen
 *    que ser reversibles con un manifiesto. Viven en Config/catalogo.php.
 *  - **Catálogo de inmuebles**: entidad propia con migración, modelos y
 *    repositorios. Sus reglas de negocio están en Config/inmuebles.php.
 *
 * Nada de esto toca archivos del core.
 */
class InmobiliariaServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'inmobiliaria');

        // API que consume el agente de IA.
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        // CRUD del catálogo, bajo el admin path y detrás del login del CRM.
        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/admin.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SetupInmobiliaria::class,
                RollbackInmobiliaria::class,
                SeedInmuebles::class,
            ]);
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        // Exposed as config('inmobiliaria') so the seeder never has to know a
        // filesystem path.
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/catalogo.php',
            'inmobiliaria'
        );

        // Reglas de negocio del catálogo de inmuebles, separadas del catálogo
        // de datos maestros porque cambian por motivos distintos.
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/inmuebles.php',
            'inmobiliaria.inmuebles'
        );

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/acl.php', 'acl');
    }
}
