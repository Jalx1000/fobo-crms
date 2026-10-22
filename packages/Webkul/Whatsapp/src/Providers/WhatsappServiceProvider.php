<?php

namespace Webkul\Whatsapp\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Whatsapp\Console\Commands\BackfillMessageBodies;
use Webkul\Whatsapp\Console\Commands\KommoCheck;
use Webkul\Whatsapp\Console\Commands\ListTemplates;
use Webkul\Whatsapp\Console\Commands\NormalizePhones;
use Webkul\Whatsapp\Console\Commands\RepairGateway;
use Webkul\Whatsapp\Console\Commands\ResolveMapLinks;
use Webkul\Whatsapp\Console\Commands\WebhookInfo;
use Webkul\Whatsapp\Http\Middleware\EnsureConversationInScope;
use Webkul\Whatsapp\Http\Middleware\EnsureWhatsappAccess;
use Webkul\Whatsapp\Jobs\NotifyAgent;
use Webkul\Whatsapp\Models\MessageProxy;

class WhatsappServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'whatsapp');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        /*
         * Lo que escribe un asesor también le llega al agente (responder: false),
         * para que cuando lo vuelvan a prender sepa qué se habló. Por el evento
         * del modelo y no en cada endpoint del inbox: hay cuatro (texto, archivo,
         * ubicación y plantilla) y alguno quedaría afuera.
         */
        MessageProxy::modelClass()::created(function ($message) {
            if (
                $message->direction === 'outbound'
                && $message->sender === 'human'
                && config('whatsapp.ai.context_when_off')
            ) {
                NotifyAgent::dispatch($message->id)->onQueue(config('whatsapp.queue'));
            }
        });

        $this->app['router']->aliasMiddleware('whatsapp_access', EnsureWhatsappAccess::class);
        $this->app['router']->aliasMiddleware('whatsapp_scope', EnsureConversationInScope::class);

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
                BackfillMessageBodies::class,
                KommoCheck::class,
                ListTemplates::class,
                NormalizePhones::class,
                RepairGateway::class,
                ResolveMapLinks::class,
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
