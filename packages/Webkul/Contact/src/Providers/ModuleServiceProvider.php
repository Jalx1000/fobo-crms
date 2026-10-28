<?php

namespace Webkul\Contact\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    protected $models = [
        \Webkul\Contact\Models\Person::class,
        \Webkul\Contact\Models\Organization::class,
        \Webkul\Contact\Models\PersonInsuranceStatus::class,
        \Webkul\Contact\Models\Branch::class,
        \Webkul\Contact\Models\PatientEvaluation::class,
        \Webkul\Contact\Models\PatientConsent::class,
    ];
}
