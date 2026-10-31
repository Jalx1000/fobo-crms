<?php

namespace Webkul\Doctor\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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

        if (! $this->app->runningInConsole() && ! Schema::hasTable('doctor_specialty')) {
            Schema::create('doctor_specialty', function (Blueprint $table) {
                $table->unsignedBigInteger('doctor_id');
                $table->unsignedBigInteger('specialty_id');
                $table->foreign('doctor_id')->references('id')->on('doctors')->onDelete('cascade');
                $table->foreign('specialty_id')->references('id')->on('specialties')->onDelete('cascade');
                $table->primary(['doctor_id', 'specialty_id']);
            });
        }
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
