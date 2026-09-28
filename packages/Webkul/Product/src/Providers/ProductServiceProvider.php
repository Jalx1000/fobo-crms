<?php

namespace Webkul\Product\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class ProductServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(Router $router)
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->mergeConfigFrom(
            __DIR__.'/../Config/caracteristicas.php',
            'caracteristicas'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../Config/adjuntos.php',
            'adjuntos'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../Config/precios.php',
            'precios'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../Config/cotizacion.php',
            'cotizacion'
        );

        /*
        | La API que consume el agente de IA vive en este módulo, no en el de
        | WhatsApp: ese módulo lo comparte el otro tenant (c21_laravel), que no
        | tiene catálogo de imprenta y no debe saber que los productos existen.
        | El módulo de dominio conoce a WhatsApp; WhatsApp no conoce al dominio.
        */
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register() {}
}
