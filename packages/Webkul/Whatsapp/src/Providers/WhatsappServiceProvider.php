<?php

namespace Webkul\Whatsapp\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Whatsapp\Console\Commands\BackfillConversationLeads;
use Webkul\Whatsapp\Console\Commands\BackfillMessageBodies;
use Webkul\Whatsapp\Console\Commands\CloseStaleHandoffs;
use Webkul\Whatsapp\Console\Commands\DiscardDuplicateLeads;
use Webkul\Whatsapp\Console\Commands\DiscardGhostLeads;
use Webkul\Whatsapp\Console\Commands\KommoCheck;
use Webkul\Whatsapp\Console\Commands\ListTemplates;
use Webkul\Whatsapp\Console\Commands\NormalizePhones;
use Webkul\Whatsapp\Console\Commands\ReopenWindow;
use Webkul\Whatsapp\Console\Commands\RepairGateway;
use Webkul\Whatsapp\Console\Commands\WebhookInfo;
use Webkul\Whatsapp\Http\Middleware\EnsureWhatsappAccess;
use Webkul\Whatsapp\Listeners\DiscardEmptyWhatsappLead;
use Webkul\Whatsapp\Listeners\LinkLeadToConversation;

class WhatsappServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'whatsapp');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->app['router']->aliasMiddleware('whatsapp_access', EnsureWhatsappAccess::class);

        /*
         * Cuando se crea un pedido con contenido, se descarta el vacío que el
         * CRM había abierto solo para ese contacto. Cuelga del evento que ya
         * dispara la API REST de Krayin, así que cubre todos los caminos sin
         * tocar vendor/.
         */
        Event::listen('lead.create.after', DiscardEmptyWhatsappLead::class);
        Event::listen('lead.create.after', LinkLeadToConversation::class);

        // Public webhook endpoints (Meta calls these; no session/CSRF).
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        /*
         * Authenticated inbox endpoints, under the admin path.
         *
         * 'user' sólo verifica que haya sesión: el permiso del módulo lo exige
         * whatsapp_access. Sin él, cualquier usuario autenticado podía leer la
         * conversación de un cliente por /whatsapp/thread.
         */
        Route::middleware(['web', 'admin_locale', 'user', 'whatsapp_access'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/admin.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillConversationLeads::class,
                BackfillMessageBodies::class,
                CloseStaleHandoffs::class,
                DiscardDuplicateLeads::class,
                DiscardGhostLeads::class,
                KommoCheck::class,
                ListTemplates::class,
                ReopenWindow::class,
                NormalizePhones::class,
                RepairGateway::class,
                WebhookInfo::class,
            ]);
        }
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/whatsapp.php',
            'whatsapp'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/menu.php',
            'menu.admin'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/acl.php',
            'acl'
        );
    }
}
