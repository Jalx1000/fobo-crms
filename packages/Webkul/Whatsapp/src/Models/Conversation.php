<?php

namespace Webkul\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Contracts\Conversation as ConversationContract;

class Conversation extends Model implements ConversationContract
{
    protected $table = 'whatsapp_conversations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'gateway',
        'wa_phone',
        'provider_conversation_id',
        'wa_name',
        'ai_enabled',
        'status',
        'last_message_at',
        'unread_count',
        'person_id',
        'lead_id',
        'last_inbound_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'ai_enabled'      => 'boolean',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
    ];

    /**
     * Resolve whether the AI agent is effectively enabled for this conversation,
     * falling back to the global default when the per-conversation flag is null.
     */
    public function aiEffective(): bool
    {
        return $this->ai_enabled ?? (bool) config('whatsapp.ai.enabled');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(LeadProxy::modelClass());
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessageProxy::modelClass(), 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(MessageProxy::modelClass(), 'conversation_id')->latestOfMany();
    }
}
