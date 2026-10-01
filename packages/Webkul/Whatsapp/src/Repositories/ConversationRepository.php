<?php

namespace Webkul\Whatsapp\Repositories;

use Webkul\Core\Eloquent\Repository;

class ConversationRepository extends Repository
{
    /**
     * Searchable fields.
     */
    protected $fieldSearchable = [
        'wa_phone',
        'wa_name',
        'status',
        'person_id',
        'lead_id',
    ];

    /**
     * Specify Model class name.
     */
    public function model(): string
    {
        return 'Webkul\Whatsapp\Contracts\Conversation';
    }
}
