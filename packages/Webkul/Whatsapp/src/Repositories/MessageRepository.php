<?php

namespace Webkul\Whatsapp\Repositories;

use Webkul\Core\Eloquent\Repository;

class MessageRepository extends Repository
{
    /**
     * Searchable fields.
     */
    protected $fieldSearchable = [
        'conversation_id',
        'direction',
        'type',
        'wa_message_id',
        'status',
    ];

    /**
     * Specify Model class name.
     */
    public function model(): string
    {
        return 'Webkul\Whatsapp\Contracts\Message';
    }
}
