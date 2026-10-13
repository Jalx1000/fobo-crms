<?php

namespace Webkul\Distribuidor\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Distribuidor\Http\Middleware\EnsureDistribuidorAccess;
use Webkul\Distribuidor\Listeners\BidonesEnContacto;

class DistribuidorServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'distribuidor');

        /*
         * La tarjeta de bidones en la ficha del contacto del CRM.
         *
         * Por evento de render y no editando el blade del core: ese archivo es de
         * Krayin y tocarlo deja un conflicto en cada actualización. Además la
         * dependencia queda apuntando bien —Distribuidor sabe de contactos, Admin no
         * necesita saber de bidones—.
         */
        Event::listen('admin.contact.persons.view.left.after', BidonesEnContacto::class);

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

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Webkul\Distribuidor\Console\Commands\EnviarRecordatorios::class,
            ]);
        }

        /*
         * El recordatorio de recompra, cada hora.
         *
         * Cada hora y no cada minuto: la precisión que importa es el DÍA —"ya debería
         * estar por quedarse sin agua"—, y correrlo cada minuto son 1.440 consultas
         * diarias para encontrar lo mismo.
         *
         * 🔴 Esto no corre solo: necesita un proceso haciendo `php artisan schedule:run`
         * cada minuto. Hoy el contenedor no tiene ninguno (ni cron ni un bucle en el
         * supervisor), así que mientras tanto el comando hay que llamarlo a mano.
         *
         * `withoutOverlapping` porque una corrida lenta —100 envíos contra la API de
         * Meta— puede pasarse de la hora, y dos corridas juntas le mandarían el mismo
         * recordatorio dos veces al mismo cliente.
         */
        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->command('distribuidor:recordatorios')
                ->hourly()
                ->withoutOverlapping();
        });
    }

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/distribuidor.php', 'distribuidor');
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/acl.php', 'acl');

        /*
         * El interruptor general de los recordatorios, editable desde la configuración
         * del CRM. Va por core_config y no sólo por .env porque lo tiene que poder
         * apagar quien maneja el negocio, no quien tiene acceso al servidor.
         */
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/core_config.php', 'core_config');
    }
}
