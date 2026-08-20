<?php

namespace Webkul\Doctor\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DoctorServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::middleware([
            'web',
            \Webkul\Admin\Http\Middleware\Locale::class,
            \Webkul\Admin\Http\Middleware\Bouncer::class,
        ])
            ->group(__DIR__.'/../Routes/web.php');

        Route::middleware(['api'])
            ->group(__DIR__.'/../Routes/api.php');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'doctor');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'doctor');

        Event::listen('admin.layout.head.after', function ($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('doctor::components.layouts.style');
        });

        // NOTE: `doctor_specialty` used to be created here, on demand, at the
        // boot of every web request. It cost an information_schema query per
        // request, and — because provider boot happens before any error
        // handling — it turned every momentary database hiccup into a 500 on
        // the whole application. The table is created by
        // 2026_01_19_120200_create_doctor_specialty_table.php, where schema
        // changes belong.
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();

        $this->app->bind(\Webkul\Doctor\Contracts\Doctor::class, \Webkul\Doctor\Models\Doctor::class);
        $this->app->bind(\Webkul\Doctor\Contracts\Specialty::class, \Webkul\Doctor\Models\Specialty::class);
        $this->app->bind(\Webkul\Doctor\Contracts\Shift::class, \Webkul\Doctor\Models\Shift::class);
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
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
