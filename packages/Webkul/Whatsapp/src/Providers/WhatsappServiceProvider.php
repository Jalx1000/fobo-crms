<?php

namespace Webkul\Whatsapp\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Whatsapp\Console\Commands\KommoCheck;
use Webkul\Whatsapp\Console\Commands\ListTemplates;
use Webkul\Whatsapp\Console\Commands\NormalizePhones;
use Webkul\Whatsapp\Console\Commands\ReactivateAgent;
use Webkul\Whatsapp\Console\Commands\RepairGateway;
use Webkul\Whatsapp\Console\Commands\SendAppointmentReminders;
use Webkul\Whatsapp\Console\Commands\WebhookInfo;

class WhatsappServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'whatsapp');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Public webhook endpoints (Meta calls these; no session/CSRF).
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        // Authenticated inbox endpoints, under the admin path.
        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/admin.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                KommoCheck::class,
                NormalizePhones::class,
                RepairGateway::class,
                ListTemplates::class,
                ReactivateAgent::class,
                SendAppointmentReminders::class,
                WebhookInfo::class,
            ]);

            // Self-schedule the daily reminder run (no host Kernel edit needed).
            // Idempotent (one reminder per appointment) and gated by
            // reminders.enabled, so a re-run never duplicates a send.
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                // Hourly, not once a day. SMD keeps syncing appointments all day
                // long, and a single 08:00 run leaves every appointment created
                // afterwards without a reminder — on 2026-07-21 that lost 20
                // patients who did have a phone. The command is idempotent (one
                // reminder per appointment, failed sends retried), so the extra
                // runs only pick up what the earlier ones could not.
                $schedule->command('whatsapp:appointment-reminders')
                    ->hourlyAt(0)
                    ->between(
                        (string) config('whatsapp.reminders.send_at', '08:00'),
                        (string) config('whatsapp.reminders.until', '20:00'),
                    )
                    ->timezone((string) config('whatsapp.reminders.timezone', 'America/La_Paz'))
                    ->withoutOverlapping();

                // Give quiet conversations back to the agent. Runs often because
                // the wait is measured in hours but should end promptly once it
                // elapses; the command itself is a no-op when nothing qualifies.
                if (config('whatsapp.ai.reactivate.enabled', true)) {
                    $schedule->command('whatsapp:reactivate-agent')
                        ->everyTenMinutes()
                        ->withoutOverlapping();
                }

                // Drain the WhatsApp queue every minute.
                //
                // Chat can't share a worker with the SMD/Dropbox jobs: those run
                // for minutes and time out, and an inbound message stuck behind
                // them means the AI agent answers a customer half an hour late.
                // This keeps its own queue without a second supervised worker.
                //
                // It stays alive listening for the whole minute instead of
                // exiting on an empty queue: a reply enqueued mid-minute would
                // otherwise wait for the next run, and chat answers arriving a
                // minute late read as the bot being broken.
                //
                // --max-time guarantees the process EXITS, and withoutOverlapping
                // means one run at a time: this must never leave a daemon behind
                // (that is what exhausted the container's memory before).
                if (config('whatsapp.queue_drainer.enabled', true)) {
                    $schedule->command(sprintf(
                        'queue:work %s --queue=%s --max-time=55 --sleep=1 --tries=3 --quiet',
                        (string) config('queue.default'),
                        (string) config('whatsapp.queue'),
                    ))
                        ->everyMinute()
                        ->withoutOverlapping()
                        ->runInBackground();
                }
            });
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
