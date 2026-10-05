<?php

namespace Webkul\Distribuidor\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected $models = [
        \Webkul\Distribuidor\Models\Pago::class,
        \Webkul\Distribuidor\Models\Entrega::class,
        \Webkul\Distribuidor\Models\MovimientoBidones::class,
        \Webkul\Distribuidor\Models\UbicacionCliente::class,
    ];
}
