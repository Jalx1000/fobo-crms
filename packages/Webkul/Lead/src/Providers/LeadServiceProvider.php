<?php

namespace Webkul\Lead\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Webkul\Lead\Console\Commands\RemoveDiscardedStage;
use Webkul\Lead\Console\Commands\ReportLeadsWithoutDetail;

class LeadServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(Router $router)
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RemoveDiscardedStage::class,
                ReportLeadsWithoutDetail::class,
            ]);
        }
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register() {}
}
