<?php

namespace Webkul\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Contracts\Conversation as ConversationContract;
use Webkul\Whatsapp\Services\AgentTestMode;

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
     *
     * Test mode sits on top of both: a number outside the whitelist never gets
     * the agent, however the switches are set. Every path that could reach the
     * agent — inbound ingestion, the notify job, the inbox composer — goes
     * through here, so this single check is the whole gate.
     */
    public function aiEffective(): bool
    {
        $enabled = $this->ai_enabled ?? (bool) config('whatsapp.ai.enabled');

        return $enabled && AgentTestMode::allows($this->wa_phone);
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
