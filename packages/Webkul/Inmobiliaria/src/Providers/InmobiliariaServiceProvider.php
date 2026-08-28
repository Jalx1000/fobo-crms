<?php

namespace Webkul\Inmobiliaria\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
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

        // La pestaña "Inmuebles" de la ficha del lead: la vista la incluye el
        // core, acá solo se le pasan los datos.
        View::composer(
            'inmobiliaria::leads.inmuebles-interes',
            \Webkul\Inmobiliaria\Listeners\ComponerInmueblesDelLead::class,
        );

        // Moneda y valor del cierre del lead. El selector vive en la vista del
        // core (junto al importe, dentro del modal); acá solo se persiste.
        Event::listen(
            'lead.update.after',
            [\Webkul\Inmobiliaria\Listeners\CierreEnMoneda::class, 'guardar'],
        );

        // Columna "Inmueble" en el listado de leads, por los eventos que el
        // DataGrid del core ya dispara.
        Event::listen(
            'datagrid.lead_data_grid.query_builder.set.after',
            [\Webkul\Inmobiliaria\Listeners\ColumnaInmuebleEnLeads::class, 'extenderConsulta'],
        );

        Event::listen(
            'datagrid.lead_data_grid.columns.prepare.after',
            [\Webkul\Inmobiliaria\Listeners\ColumnaInmuebleEnLeads::class, 'agregarColumna'],
        );

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
