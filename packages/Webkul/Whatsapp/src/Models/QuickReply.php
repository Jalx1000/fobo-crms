<?php

namespace Webkul\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Contracts\QuickReply as QuickReplyContract;

class QuickReply extends Model implements QuickReplyContract
{
    protected $table = 'whatsapp_quick_replies';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'shortcut',
        'title',
        'content',
    ];

    /**
     * Owner. Null means the reply is global (whole team).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProxy::modelClass());
    }

    /**
     * Replies the given user can see: the team's globals plus their own.
     */
    public function scopeVisibleTo($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereNull('user_id')->orWhere('user_id', $userId);
        });
    }
}
