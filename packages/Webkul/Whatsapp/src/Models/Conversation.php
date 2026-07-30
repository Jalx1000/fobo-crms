<?php

namespace Webkul\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Contracts\Conversation as ConversationContract;

class Conversation extends Model implements ConversationContract
{
    /**
     * Handoff lifecycle. `null` means the conversation was never derived.
     *
     * requested → nobody owns it yet; it sits in the team pool.
     * assigned  → a named advisor is responsible.
     * resolved  → closed; the AI agent may be back in charge.
     */
    public const HANDOFF_REQUESTED = 'requested';

    public const HANDOFF_ASSIGNED = 'assigned';

    public const HANDOFF_RESOLVED = 'resolved';

    /** States in which a customer is still waiting on a human. */
    public const HANDOFF_OPEN_STATES = [self::HANDOFF_REQUESTED, self::HANDOFF_ASSIGNED];

    public const HANDOFF_SOURCES = ['ai', 'human'];

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
        'handoff_state',
        'handoff_reason',
        'handoff_source',
        'handoff_requested_at',
        'assigned_user_id',
        'assigned_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'ai_enabled'           => 'boolean',
        'last_message_at'      => 'datetime',
        'last_inbound_at'      => 'datetime',
        'handoff_requested_at' => 'datetime',
        'assigned_at'          => 'datetime',
    ];

    /**
     * Resolve whether the AI agent is effectively enabled for this conversation,
     * falling back to the global default when the per-conversation flag is null.
     */
    public function aiEffective(): bool
    {
        return $this->ai_enabled ?? (bool) config('whatsapp.ai.enabled');
    }

    /**
     * A customer is waiting on a human: either nobody has claimed it yet
     * (requested) or an advisor owns it (assigned).
     *
     * While this holds, the AI agent must stay out — see Services\Handoff and
     * the guard in InboxController::toggleAgent().
     */
    public function handoffOpen(): bool
    {
        return in_array($this->handoff_state, self::HANDOFF_OPEN_STATES, true);
    }

    /**
     * Derived but unclaimed: it belongs to the team pool, and whoever takes it
     * first owns it.
     */
    public function handoffPooled(): bool
    {
        return $this->handoff_state === self::HANDOFF_REQUESTED
            && $this->assigned_user_id === null;
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(UserProxy::modelClass(), 'assigned_user_id');
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
