<?php

namespace Webkul\Messenger\Providers;

use Illuminate\Support\ServiceProvider;
use Webkul\Messenger\Console\Commands\BackfillProfiles;
use Webkul\Messenger\Console\Commands\ConnectPage;
use Webkul\Messenger\Gateways\MessengerGateway;

/**
 * Messenger is not an inbox of its own: it is one more channel of the shared
 * messaging inbox owned by Webkul\Whatsapp. This provider therefore registers
 * exactly two things — the channel and its webhook — and nothing that would
 * duplicate a store, a menu entry or an API.
 */
class MessengerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Public webhook endpoint (Meta calls it; no session/CSRF).
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            // The webhook URL and its security posture are reported by the
            // inbox's own command: `php artisan whatsapp:webhook-info messenger`.
            $this->commands([ConnectPage::class, BackfillProfiles::class]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/messenger.php', 'messenger');

        $this->registerChannel();
    }

    /**
     * Declare Messenger in the inbox's channel registry.
     *
     * Done at register() — like any mergeConfigFrom — so the entry exists
     * before anything resolves a driver, and so `config:cache` captures it.
     *
     * Precedence matters: the keys that define what this channel *is* are
     * written LAST, so they win. An earlier version of this module wrote the
     * entry from boot() with a different shape, and a `config:cache` built back
     * then survives on disk — letting a stale cached entry silently override
     * the driver, the family or the AI default of the running code.
     * Credentials still come from config/messenger.php and the environment.
     */
    protected function registerChannel(): void
    {
        $key = 'whatsapp.gateways.messenger';

        config([$key => array_merge(
            (array) config($key, []),
            (array) config('messenger', []),
            [
                'driver' => MessengerGateway::class,
                'label'  => 'Messenger',

                // Its own family: a PSID is not a phone number, so a Messenger
                // thread must never be merged with a WhatsApp one.
                'family' => 'messenger',

                // Leads opened from a Messenger thread report this source, so
                // the pipeline shows what the channel is actually bringing in.
                'lead_source' => 'Messenger',

                // Interruptor del canal, no solo el valor inicial: en false el
                // agente IA no corre en NINGUNA conversacion de Messenger, y el
                // switch del inbox queda bloqueado (ver Conversation::aiEffective
                // e InboxController::toggleAgent).
                //
                // Arranca apagado a proposito: el agente esta escrito y probado
                // para WhatsApp, y soltarlo en un canal que nadie valido es una
                // decision que toma una persona.
                'ai_default' => env('MESSENGER_AI_ENABLED', false),
            ],
        )]);
    }
}
