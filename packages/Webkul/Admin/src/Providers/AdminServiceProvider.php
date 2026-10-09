<?php

namespace Webkul\Admin\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Admin\Exceptions\Handler;
use Webkul\Admin\Http\Middleware\Bouncer as BouncerMiddleware;
use Webkul\Admin\Http\Middleware\Locale;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    /**
     * Aviso al asesor cuando su pedido PASA a "Cliente confirmado".
     *
     * El listener se registra acá y NO con un booted() en el modelo Lead, que
     * fue el primer intento: `Lead` usa el trait LogsActivity, que define su
     * propio booted(), y un método en la clase gana sobre el del trait sin que
     * PHP avise. Resultado medido: el log de actividades de los pedidos dejó de
     * escribirse — crear un lead pasó a generar 0 actividades `system`, que es
     * el historial del que dependen el tablero y la vista del pedido.
     *
     * Se mira el CAMBIO de etapa y no el estado: colgado de lead.update.after,
     * cada edición posterior de un pedido ya confirmado reenviaba el correo.
     * `updated` corre en el save exacto que cambió la etapa, antes de que
     * CitySyncService y applyLeadValueRule vuelvan a guardar y dejen
     * wasChanged() limpio.
     */
    protected function avisarPedidosConfirmados(): void
    {
        $modelo = \Webkul\Lead\Models\LeadProxy::modelClass();

        $modelo::updated(function ($lead) {
            if (! $lead->wasChanged('lead_pipeline_stage_id')) {
                return;
            }

            if (! core()->getConfigData('leads.notifications.confirmed_order.enabled')) {
                return;
            }

            /*
             * La etapa se lee de la base y no por $lead->stage: la relación
             * queda cacheada en la instancia y devolvía la VIEJA cuando el
             * pedido ya la había cargado antes del cambio.
             */
            $code = \Illuminate\Support\Facades\DB::table('lead_pipeline_stages')
                ->where('id', $lead->lead_pipeline_stage_id)
                ->value('code');

            if ($code !== 'cliente-confirmado') {
                return;
            }

            // Nunca puede costar el guardado del pedido.
            try {
                \Webkul\Admin\Jobs\NotifyAdvisorConfirmedLead::dispatch($lead->id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('No se pudo encolar el aviso de pedido confirmado', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        });
    }

    public function boot(Router $router): void
    {
        $this->avisarPedidosConfirmados();

        $router->aliasMiddleware('user', BouncerMiddleware::class);

        $router->aliasMiddleware('admin_locale', Locale::class);

        include __DIR__.'/../Http/helpers.php';

        Route::middleware(['web', 'admin_locale', 'user'])
            ->prefix(config('app.admin_path'))
            ->group(__DIR__.'/../Routes/Admin/web.php');

        Route::middleware(['web', 'admin_locale'])
            ->group(__DIR__.'/../Routes/Front/web.php');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'admin');

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'admin');

        Blade::anonymousComponentPath(__DIR__.'/../Resources/views/components', 'admin');

        $this->app->bind(ExceptionHandler::class, Handler::class);

        Relation::morphMap([
            'leads'         => \Webkul\Lead\Models\Lead::class,
            'organizations' => \Webkul\Contact\Models\Organization::class,
            'persons'       => \Webkul\Contact\Models\Person::class,
            'products'      => \Webkul\Product\Models\Product::class,
            'quotes'        => \Webkul\Quote\Models\Quote::class,
            'warehouses'    => \Webkul\Warehouse\Models\Warehouse::class,
        ]);

        $this->app->register(EventServiceProvider::class);
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerFacades();

        $this->registerConfig();
    }

    /**
     * Register Bouncer as a singleton.
     */
    protected function registerFacades(): void
    {
        $loader = AliasLoader::getInstance();

        $loader->alias('Bouncer', \Webkul\Admin\Facades\Bouncer::class);

        $this->app->singleton('bouncer', function () {
            return new \Webkul\Admin\Bouncer;
        });
    }

    /**
     * Register package config.
     */
    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/Config/acl.php', 'acl');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/menu.php', 'menu.admin');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/core_config.php', 'core_config');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/attribute_lookups.php', 'attribute_lookups');

        $this->mergeConfigFrom(dirname(__DIR__).'/Config/attribute_entity_types.php', 'attribute_entity_types');
    }
}
