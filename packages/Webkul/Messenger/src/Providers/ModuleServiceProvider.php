<?php

namespace Webkul\Messenger\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;
use Webkul\Messenger\Models\SocialConnection;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The models to be used by this module. Registered with Concord so an
     * installation can swap the implementation like any other Krayin model.
     *
     * Conversations and messages are deliberately absent: Messenger stores none
     * of its own - it writes to the shared inbox owned by Webkul\Whatsapp.
     *
     * @var array
     */
    protected $models = [
        SocialConnection::class,
    ];
}
