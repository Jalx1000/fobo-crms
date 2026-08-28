<?php

namespace Webkul\Whatsapp\Repositories;

use Illuminate\Database\QueryException;
use Webkul\Core\Eloquent\Repository;
use Webkul\Whatsapp\Models\Handoff;

class HandoffRepository extends Repository
{
    protected $fieldSearchable = ['conversation_id', 'user_id', 'state'];

    public function model(): string
    {
        return 'Webkul\Whatsapp\Contracts\Handoff';
    }

    /**
     * El handoff vigente de una conversación, si lo hay.
     */
    public function abiertoDe(int $conversationId): ?Handoff
    {
        return $this->model->newQuery()
            ->where('conversation_id', $conversationId)
            ->whereIn('state', ['abierto', 'tomado'])
            ->first();
    }

    /**
     * Deriva la conversación a un asesor. Idempotente.
     *
     * Si ya hay uno vigente devuelve ese mismo, sin crear otro y sin cambiarle
     * el titular: el agente ya le dijo al cliente quién le va a escribir, y
     * cambiar de persona a mitad de camino sería peor que no derivar.
     *
     * La idempotencia se apoya en el índice único de la tabla, no solo en el
     * SELECT previo: dos requests simultáneos del agente hacen que uno choque
     * contra la restricción, y ahí se devuelve el que ganó.
     *
     * @param  array<string, mixed>  $datos
     */
    public function abrir(int $conversationId, int $userId, array $datos = []): Handoff
    {
        if ($vigente = $this->abiertoDe($conversationId)) {
            return $vigente;
        }

        try {
            return $this->model->newQuery()->create([
                'conversation_id'   => $conversationId,
                'user_id'           => $userId,
                'referencia_id'     => $datos['referencia_id'] ?? null,
                'referencia_codigo' => $datos['referencia_codigo'] ?? null,
                'reason'            => $datos['reason'] ?? null,
                'state'             => 'abierto',
                'opened_at'         => now(),
                'abierto_uid'       => Handoff::VIGENTE,
            ]);
        } catch (QueryException $e) {
            // Carrera perdida contra otro request: el índice único hizo su
            // trabajo. El handoff existe, que es lo que el agente pidió.
            if ($vigente = $this->abiertoDe($conversationId)) {
                return $vigente;
            }

            throw $e;
        }
    }

    /**
     * Cierra el handoff y devuelve la conversación al agente.
     */
    public function cerrar(int $conversationId): ?Handoff
    {
        $handoff = $this->abiertoDe($conversationId);

        $handoff?->update([
            'state'       => 'cerrado',
            'closed_at'   => now(),
            'abierto_uid' => null,
        ]);

        return $handoff;
    }
}
