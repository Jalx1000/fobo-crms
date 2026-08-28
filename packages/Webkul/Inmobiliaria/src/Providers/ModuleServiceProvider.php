<?php

namespace Webkul\Inmobiliaria\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Modelos del módulo, registrados en Concord para que el resto del CRM los
     * consuma por proxy y una instalación pueda sustituirlos sin tocar código.
     *
     * @var array
     */
    protected $models = [
        \Webkul\Inmobiliaria\Models\Inmueble::class,
        \Webkul\Inmobiliaria\Models\InmuebleMedia::class,
    ];
}
