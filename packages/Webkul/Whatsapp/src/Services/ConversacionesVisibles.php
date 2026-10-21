<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\Alcance;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Qué conversaciones de WhatsApp puede ver y atender el usuario actual.
 *
 * Spec docs/specs/tablero-por-asesor.md:
 *   - D10: un asesor ve las conversaciones DE SUS LEADS —el lead vinculado a la
 *     conversación, o cualquier lead del contacto— y nada más. La derivación
 *     sola no da acceso: se deriva siempre al dueño del lead (D13).
 *   - D1: administradores y supervisores ven todas las que tienen contacto o lead.
 *   - D11: las que no tienen ni contacto ni lead las ven solo los usuarios de
 *     `whatsapp.ven_sin_asignar_user_ids`, sin importar el rol.
 *
 * La lista del inbox, CADA endpoint que recibe una conversación y la tarjeta
 * «Qué necesita atención ahora» pasan por acá. Filtrar solo la lista no
 * alcanza: el id viaja en la URL y cualquiera puede cambiarlo.
 */
class ConversacionesVisibles
{
    public function __construct(protected Alcance $alcance) {}

    /**
     * Limita una consulta sobre `whatsapp_conversations` a las visibles.
     *
     * Acepta tanto el builder de Eloquent como `DB::table(...)`.
     */
    public function aplicar($query)
    {
        // El administrador ve todas, sin ningún filtro.
        if ($this->alcance->sinRestriccion()) {
            return $query;
        }

        $veSinAsignar = $this->alcance->veSinAsignar();
        $userId = $this->alcance->userId();

        return $query->where(function ($q) use ($veSinAsignar, $userId) {
            if ($userId === null) {
                $q->whereNotNull('whatsapp_conversations.person_id')
                    ->orWhereNotNull('whatsapp_conversations.lead_id');
            } else {
                $q->whereExists(fn ($l) => $l->select(DB::raw(1))
                    ->from('leads')
                    ->where('leads.user_id', $userId)
                    ->where(fn ($w) => $w
                        ->whereColumn('leads.id', 'whatsapp_conversations.lead_id')
                        ->orWhereColumn('leads.person_id', 'whatsapp_conversations.person_id')));
            }

            if ($veSinAsignar) {
                $q->orWhere(fn ($s) => $s
                    ->whereNull('whatsapp_conversations.person_id')
                    ->whereNull('whatsapp_conversations.lead_id'));
            }
        });
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
     * ¿Puede ver la ficha de este contacto desde el inbox?
     */
    public function puedeVerContacto(int|string $personId): bool
    {
        $userId = $this->alcance->userId();

        if ($userId === null) {
            return true;
        }

        return DB::table('leads')
            ->where('person_id', $personId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * ¿Puede tomar esta derivación? Solo el asesor asignado (D14).
     *
     * Una derivación sin asignado (no debería pasar: siempre hay asesor por
     * defecto) la puede tomar quien ve el total, para que no quede huérfana.
     */
    public function puedeTomar($handoff, ?int $userId): bool
    {
        if (! $handoff || ! $userId || $handoff->state !== 'abierto') {
            return false;
        }

        // El administrador puede tomar cualquier derivación.
        if ($this->alcance->sinRestriccion()) {
            return true;
        }

        if ($handoff->user_id === null) {
            return $this->alcance->veTotal();
        }

        return (int) $handoff->user_id === $userId;
    }
}
