<?php

namespace Webkul\Whatsapp\Models;

use Illuminate\Database\Eloquent\Builder;
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
    protected $table = 'whatsapp_conversations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    /*
     * Estados del handoff. `requested` = derivada pero sin dueño (el pool);
     * `assigned` = un asesor concreto la debe; `resolved` = cerrada.
     */
    public const HANDOFF_REQUESTED = 'requested';

    public const HANDOFF_ASSIGNED = 'assigned';

    public const HANDOFF_RESOLVED = 'resolved';

    public const HANDOFF_SOURCES = ['ai', 'human'];

    protected $fillable = [
        'gateway',
        'wa_phone',
        'provider_conversation_id',
        'wa_name',
        'ai_enabled',
        'ai_disabled_at',
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
        'handoff_city_id',
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
        'ai_disabled_at'       => 'datetime',
        'last_message_at'      => 'datetime',
        'last_inbound_at'      => 'datetime',
        'handoff_requested_at' => 'datetime',
        'assigned_at'          => 'datetime',
    ];

    /**
     * Marca cuándo se apagó el agente, sin importar quién lo apagó.
     *
     * Va en el modelo y no en cada llamador a propósito: hoy se apaga desde el
     * interruptor del inbox y desde Handoff::request(), y cualquier camino
     * nuevo lo heredaría igual. Sin esta marca no hay cómo saber hace cuánto
     * está callado —`updated_at` cambia con cada mensaje entrante— y así fue
     * como quedaron 100 conversaciones con el bot apagado que nadie reactivó.
     */
    protected static function booted(): void
    {
        static::saving(function (self $conversacion) {
            if (! $conversacion->isDirty('ai_enabled')) {
                return;
            }

            $conversacion->ai_disabled_at = $conversacion->ai_enabled === false ? now() : null;
        });
    }

    /**
     * Resolve whether the AI agent is effectively enabled for this conversation,
     * falling back to the global default when the per-conversation flag is null.
     *
     * Una derivación abierta manda por encima del flag. Handoff::request() apaga
     * ai_enabled, pero eso es un dato que se puede desincronizar: la conversación
     * #323 llevaba 12 días asignada a un asesor con ai_enabled en true, así que
     * el agente seguía contestando por encima de la persona que la atendía.
     * Mientras haya alguien del otro lado, el agente calla.
     */
    public function aiEffective(): bool
    {
        if ($this->handoffOpen()) {
            return false;
        }

        return $this->ai_enabled ?? (bool) config('whatsapp.ai.enabled');
    }

    /**
     * ¿Hay una derivación abierta? Es lo que silencia al agente y lo que hace
     * que sus intentos de responder devuelvan 409.
     */
    public function handoffOpen(): bool
    {
        return in_array($this->handoff_state, [self::HANDOFF_REQUESTED, self::HANDOFF_ASSIGNED], true);
    }

    /**
     * Derivada pero sin dueño: la toma el primero que llegue.
     */
    public function handoffPooled(): bool
    {
        return $this->handoff_state === self::HANDOFF_REQUESTED && $this->assigned_user_id === null;
    }

    /**
     * Las conversaciones que un usuario puede ver, según su view_permission.
     *
     * Misma limitación que Krayin aplica a los leads: `$userIds` es lo que
     * devuelve bouncer()->getAuthorizedUserIds() — null para un usuario
     * "global" (ve todo), o la lista de usuarios cuyo trabajo puede ver
     * ("group": los de sus grupos; "individual": sólo él).
     *
     * Restringido, ve una conversación si cumple ALGUNA:
     *   (a) está asignada a uno de esos usuarios (assigned_user_id);
     *   (b) tiene un lead de uno de esos usuarios (leads.user_id), por
     *       lead_id o por person_id;
     *   (c) está en la bolsa: derivada y sin dueño, para poder tomarla.
     *
     * Ojo: [] NO es "global". Krayin hace `if ($ids = ...)` y trata la lista
     * vacía como "sin filtro"; acá un "group" sin grupos ve sólo la bolsa.
     *
     * Spec: tasks/spec-api-mobile-whatsapp.md, "Visibilidad".
     */
    public function scopeVisibleTo(Builder $query, ?array $userIds): Builder
    {
        if ($userIds === null) {
            return $query;
        }

        $table = $this->getTable();

        /*
         * (b) cuenta leads abiertos Y cerrados, igual que LeadDataGrid, que
         * filtra por user_id sin mirar el estado: el asesor ve en WhatsApp los
         * mismos clientes que ve en su listado de leads. EXISTS y no JOIN para
         * no duplicar filas en el listado, que deduplica con ROW_NUMBER().
         */
        return $query->where(function (Builder $q) use ($userIds, $table) {
            $q->whereIn("{$table}.assigned_user_id", $userIds)
                ->orWhereExists(fn ($sub) => $sub->from('leads')
                    ->whereColumn('leads.person_id', "{$table}.person_id")
                    ->whereIn('leads.user_id', $userIds))
                ->orWhereExists(fn ($sub) => $sub->from('leads')
                    ->whereColumn('leads.id', "{$table}.lead_id")
                    ->whereIn('leads.user_id', $userIds))
                ->orWhere(fn ($pool) => $pool
                    ->where("{$table}.handoff_state", self::HANDOFF_REQUESTED)
                    ->whereNull("{$table}.assigned_user_id"));
        });
    }

    /**
     * Lo mismo, para el usuario de la request (sesión web o token mobile).
     */
    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        // Sin usuario no se ve nada. Ninguna ruta llega acá sin uno (las
        // protegen `user` y `auth:sanctum`), y un control de acceso que falla
        // abierto es justo el hueco que esto viene a cerrar.
        if (! auth()->guard('user')->check()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->visibleTo(bouncer()->getAuthorizedUserIds());
    }

    public function isVisibleToCurrentUser(): bool
    {
        return static::query()->whereKey($this->getKey())->visibleToCurrentUser()->exists();
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
