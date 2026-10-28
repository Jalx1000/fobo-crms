<?php

namespace Webkul\Product\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected $models = [
        \Webkul\Product\Models\Product::class,
        \Webkul\Product\Models\ProductInventory::class,
        \Webkul\Product\Models\Promotion::class,
        \Webkul\Product\Models\ServiceZone::class,
        \Webkul\Product\Models\ServiceCategory::class,
    ];
}
