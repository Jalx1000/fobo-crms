<?php

namespace Webkul\Whatsapp\Providers;

use Webkul\Core\Providers\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Models registered by this module (populated in Sprint 0 backend work).
     *
     * @var array
     */
    protected $models = [
        \Webkul\Whatsapp\Models\Conversation::class,
        \Webkul\Whatsapp\Models\Message::class,
        \Webkul\Whatsapp\Models\QuickReply::class,
    ];
}
