<?php

namespace Webkul\Doctor\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The models to be used by this module.
     *
     * @var array
     */
    protected $models = [
        \Webkul\Doctor\Models\Doctor::class,
        \Webkul\Doctor\Models\Specialty::class,
    ];
}
