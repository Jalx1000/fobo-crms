<?php

namespace Webkul\Whatsapp\Repositories;

use Webkul\Core\Eloquent\Repository;

class QuickReplyRepository extends Repository
{
    /**
     * Searchable fields.
     */
    protected $fieldSearchable = [
        'shortcut',
        'title',
        'user_id',
    ];

    /**
     * Specify Model class name.
     */
    public function model(): string
    {
        return 'Webkul\Whatsapp\Contracts\QuickReply';
    }
}
