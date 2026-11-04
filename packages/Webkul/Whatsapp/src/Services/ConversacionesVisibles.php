<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\Alcance;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Qué conversaciones de WhatsApp puede ver y atender el usuario actual.
 *
 * Spec docs/specs/tablero-por-asesor.md (adaptado de ponce_de_leon):
 *   - D8: un ejecutivo ve las conversaciones DE LAS QUE ES ASESOR, con la misma
 *     regla que el filtro «Míos»: la derivación abierta a su nombre; si no hay
 *     derivación abierta, el pedido suyo; sin pedido, el contacto suyo. Así
 *     «Míos» y «lo que puedo ver» son el mismo conjunto por construcción.
 *   - D1: Administrador y Supervisores ven todas las que tienen contacto o pedido.
 *   - D9/D11: las que no tienen ni contacto ni pedido, solo el Administrador.
 *
 * La lista del inbox y CADA endpoint que recibe una conversación, un contacto
 * o un pedido pasan por acá. Filtrar solo la lista no alcanza: el id viaja en
 * la URL y cualquiera puede cambiarlo.
 */
class ConversacionesVisibles
{
    public function __construct(protected Alcance $alcance) {}

    /**
     * Las conversaciones de un asesor: derivación abierta a su nombre → dueño
     * del pedido → dueño del contacto (el reparto producto × ciudad escribe
     * esos dueños). Es la regla del filtro «Míos» y del selector de asesor.
     */
    public function deAsesor($query, int $userId)
    {
        $openStates = ConversationProxy::modelClass()::HANDOFF_OPEN_STATES;

        return $query->where(function ($w) use ($userId, $openStates) {
            $w->where(fn ($h) => $h->whereIn('whatsapp_conversations.handoff_state', $openStates)
                ->where('whatsapp_conversations.assigned_user_id', $userId))
                ->orWhere(fn ($o) => $o
                    ->where(fn ($sinDerivar) => $sinDerivar->whereNull('whatsapp_conversations.handoff_state')
                        ->orWhereNotIn('whatsapp_conversations.handoff_state', $openStates)
                        ->orWhereNull('whatsapp_conversations.assigned_user_id'))
                    ->where(fn ($duenio) => $duenio
                        ->whereIn('whatsapp_conversations.lead_id', DB::table('leads')->where('user_id', $userId)->select('id'))
                        ->orWhere(fn ($p) => $p->whereNull('whatsapp_conversations.lead_id')
                            ->whereIn('whatsapp_conversations.person_id', DB::table('persons')->where('user_id', $userId)->select('id')))));
        });
    }

    /**
     * Limita una consulta sobre `whatsapp_conversations` a las visibles.
     * Acepta tanto el builder de Eloquent como `DB::table(...)`.
     */
    public function aplicar($query)
    {
        // El administrador ve todas, sin ningún filtro (D11).
        if ($this->alcance->sinRestriccion()) {
            return $query;
        }

        $userId = $this->alcance->userId();

        if ($userId === null) {
            // Supervisores: todas menos las que no tienen ni contacto ni pedido (D9).
            return $query->where(fn ($q) => $q->whereNotNull('whatsapp_conversations.person_id')
                ->orWhereNotNull('whatsapp_conversations.lead_id'));
        }

        return $this->deAsesor($query, $userId);
    }

    public function puedeVer($conversation): bool
    {
        if (! $conversation) {
            return false;
        }

        return $this->aplicar(ConversationProxy::modelClass()::query()->whereKey($conversation->getKey()))->exists();
    }

    /**
     * 404 y no 403: decirle «no tenés permiso» confirma que el cliente existe.
     */
    public function exigir($conversation): void
    {
        abort_unless($this->puedeVer($conversation), 404);
    }

    /**
     * ¿Puede ver este contacto desde el inbox? Si es suyo, si tiene un pedido
     * suyo, o si alguna conversación del contacto le es visible (p. ej. una
     * derivación abierta a su nombre).
     */
    public function puedeVerContacto(int|string $personId): bool
    {
        if ($this->alcance->userId() === null) {
            return true;
        }

        $userId = $this->alcance->userId();

        return DB::table('persons')->where('id', $personId)->where('user_id', $userId)->exists()
            || DB::table('leads')->where('person_id', $personId)->where('user_id', $userId)->exists()
            || $this->aplicar(ConversationProxy::modelClass()::query()->where('person_id', $personId))->exists();
    }

    /**
     * ¿Puede tocar este pedido desde el inbox? Si es suyo o si su conversación
     * le es visible.
     */
    public function puedeVerPedido(int|string $leadId): bool
    {
        if ($this->alcance->userId() === null) {
            return true;
        }

        return DB::table('leads')->where('id', $leadId)->where('user_id', $this->alcance->userId())->exists()
            || $this->aplicar(ConversationProxy::modelClass()::query()->where('lead_id', $leadId))->exists();
    }
}
