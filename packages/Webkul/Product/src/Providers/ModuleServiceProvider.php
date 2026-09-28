<?php

namespace Webkul\Product\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected $models = [
        \Webkul\Product\Models\Product::class,
        \Webkul\Product\Models\ProductInventory::class,
        \Webkul\Product\Models\ProductMedia::class,
        \Webkul\Product\Models\ProductPrice::class,
        \Webkul\Product\Models\ProductVariante::class,
        \Webkul\Product\Models\ProductoResponsable::class,
    ];
}
