<?php

namespace Webkul\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Contracts\Handoff as HandoffContract;

/**
 * Una conversación derivada a un humano.
 *
 * Mientras hay uno abierto el agente de IA queda mudo: no se le notifica, y si
 * intenta responder igual, el endpoint le devuelve 409. Esa es la garantía que
 * evita que el cliente reciba dos voces a la vez.
 */
class Handoff extends Model implements HandoffContract
{
    protected $table = 'whatsapp_handoffs';

    protected $fillable = [
        'conversation_id', 'user_id', 'referencia_id', 'referencia_codigo',
        'reason', 'state', 'opened_at', 'taken_at', 'closed_at', 'escalated_at',
        'abierto_uid',
    ];

    protected $casts = [
        'opened_at'    => 'datetime',
        'taken_at'     => 'datetime',
        'closed_at'    => 'datetime',
        'escalated_at' => 'datetime',
    ];

    /**
     * Marcador del índice único parcial.
     *
     * Vale 1 mientras el handoff está vigente y NULL cuando se cierra. Como
     * MySQL considera distintos a los NULL dentro de un índice único, esto
     * permite un solo handoff vigente por conversación y a la vez un histórico
     * ilimitado de handoffs cerrados.
     */
    public const VIGENTE = 1;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ConversationProxy::modelClass(), 'conversation_id');
    }

    /**
     * El asesor titular al que se derivó.
     */
    public function asesor(): BelongsTo
    {
        return $this->belongsTo(UserProxy::modelClass(), 'user_id');
    }

    /**
     * "Abierto" incluye 'tomado': el asesor ya está escribiendo, y el agente
     * tiene todavía más razones para callarse.
     */
    public function estaAbierto(): bool
    {
        return in_array($this->state, ['abierto', 'tomado'], true);
    }

    /**
     * ¿Pasó el tiempo sin que nadie lo tome, y todavía no se avisó al supervisor?
     */
    public function debeEscalar(): bool
    {
        if ($this->state !== 'abierto' || $this->escalated_at !== null) {
            return false;
        }

        $minutos = (int) config('inmobiliaria.inmuebles.handoff.escalar_tras_minutos', 15);

        return $this->opened_at->addMinutes($minutos)->isPast();
    }
}
