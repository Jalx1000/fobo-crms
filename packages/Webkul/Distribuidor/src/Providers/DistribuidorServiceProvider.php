<?php

namespace Webkul\Distribuidor\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Distribuidor\Http\Middleware\EnsureDistribuidorAccess;
use Webkul\Distribuidor\Listeners\AsignarDistribuidorDeZona;
use Webkul\Distribuidor\Listeners\BidonesEnContacto;
use Webkul\Distribuidor\Listeners\CiudadRespondida;
use Webkul\Distribuidor\Listeners\ClienteCambiaDeDistribuidor;
use Webkul\Distribuidor\Listeners\DistribuidorDadoDeBaja;

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

        /*
         * Cada pedido queda a nombre del distribuidor de su ciudad.
         *
         * Se escucha `lead.city.synced` y no `lead.create.after`: el segundo lo
         * disparan los CONTROLADORES, así que un pedido creado por la API del agente
         * —que entra directo por el repositorio— no lo dispara nunca. El primero sale
         * del sincronizador de ciudad, que es el único punto por el que pasan todos
         * los caminos: la API, el formulario web, el kanban y el Punto.
         */
        Event::listen('lead.city.synced', AsignarDistribuidorDeZona::class);

        // Pedido marcado como entregado desde el CRM (kanban, ficha, API): recordatorio de
        // recompra. El botón "Entregado" del Punto ya lo hacía; los otros caminos, no.
        Event::listen('lead.city.synced', \Webkul\Distribuidor\Listeners\RecompraAlEntregar::class);

        /*
         * Un cliente es de UN distribuidor: si un encargado le cambia el dueño, sus
         * pedidos abiertos y su conversación se mudan con él; si se da de baja a un
         * distribuidor, sus clientes se reparten entre los demás de la ciudad.
         */
        Event::listen('contacts.person.update.after', ClienteCambiaDeDistribuidor::class);
        Event::listen('settings.user.update.after', DistribuidorDadoDeBaja::class);

        // La respuesta del cliente a la pregunta de ciudad por WhatsApp.
        Event::listen('whatsapp.message.received', CiudadRespondida::class);

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
                \Webkul\Distribuidor\Console\Commands\RepartirClientes::class,
                \Webkul\Distribuidor\Console\Commands\PreguntarCiudad::class,
                \Webkul\Distribuidor\Console\Commands\ProgramarRecompra::class,
            ]);
        }

        /*
         * El recordatorio de recompra, cada hora.
         *
         * Cada hora y no cada minuto: la precisión que importa es el DÍA —"ya debería
         * estar por quedarse sin agua"—, y correrlo cada minuto son 1.440 consultas
         * diarias para encontrar lo mismo.
         *
         * Lo corre el bucle de `schedule:run` que levanta docker/entrypoint.sh.
         *
         * `withoutOverlapping` porque una corrida lenta —100 envíos contra la API de
         * Meta— puede pasarse de la hora, y dos corridas juntas le mandarían el mismo
         * recordatorio dos veces al mismo cliente.
         */
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('distribuidor:recordatorios')
                ->hourly()
                ->withoutOverlapping();

            /*
             * La pregunta de ciudad, cada 10 minutos: tiene que salir antes de que se
             * cierre la ventana de 24 h, y una corrida por hora dejaba muy poco margen.
             */
            $schedule->command('distribuidor:preguntar-ciudad')
                ->everyTenMinutes()
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

        /*
         * El lookup `distribuidores`, para que "Asesor Asignado" ofrezca sólo a quien
         * reparte. Se agrega desde acá y no editando la config del core: el rol
         * Distribuidor es un concepto de este módulo.
         */
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/attribute_lookups.php', 'attribute_lookups');
    }
}
