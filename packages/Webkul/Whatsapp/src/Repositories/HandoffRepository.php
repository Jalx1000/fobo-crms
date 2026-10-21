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
     * Un asesor se hace cargo de la conversación.
     *
     * Es un UPDATE condicional, no un leer-y-escribir: InnoDB serializa los
     * updates sobre la misma clave primaria, así que con dos asesores tocando
     * el botón a la vez exactamente uno recibe $afectadas === 1 y el otro se
     * entera de que llegó tarde.
     *
     * Toma quien aprieta, no el titular. Si el titular no está y otro asesor
     * levanta la conversación, el handoff pasa a ser suyo — el cliente necesita
     * una respuesta más de lo que necesita al asesor original. Quién era el
     * titular queda en la nota de actividad que deja el controlador.
     *
     * @return Handoff|null null = ya lo tomó otro (o no hay handoff abierto).
     */
    public function tomar(int $conversationId, int $userId): ?Handoff
    {
        $afectadas = $this->model->newQuery()
            ->where('conversation_id', $conversationId)
            ->where('state', 'abierto')
            ->update([
                'state'      => 'tomado',
                'user_id'    => $userId,
                'taken_at'   => now(),
                'updated_at' => now(),
            ]);

        return $afectadas === 1 ? $this->abiertoDe($conversationId) : null;
    }

    /**
     * Un asesor escribe en una conversación que nadie derivó: se la queda.
     *
     * La derivación nace ya 'tomado' y no 'abierto': el asesor no está
     * esperando que alguien la levante, ya está escribiéndole al cliente. Que
     * quede registrada sirve para saber quién atendió y para que el cierre
     * automático la alcance si después la abandona.
     *
     * Devuelve null cuando ya había una vigente y no hubo nada que hacer, para
     * que quien llama sepa si tiene que auditar el movimiento.
     */
    public function autoderivar(int $conversationId, int $userId): ?Handoff
    {
        if ($vigente = $this->abiertoDe($conversationId)) {
            // Ya derivada: la toma quien escribe solo si es el asesor asignado
            // (o si no tiene asignado). Escribir no le quita la derivación al
            // asesor al que se la dieron (spec tablero-por-asesor, D14).
            $esSuya = $vigente->user_id === null
                || (int) $vigente->user_id === $userId
                || app(\Webkul\Admin\Helpers\Alcance::class)->sinRestriccion();

            return $vigente->state === 'abierto' && $esSuya
                ? $this->tomar($conversationId, $userId)
                : null;
        }

        try {
            return $this->model->newQuery()->create([
                'conversation_id' => $conversationId,
                'user_id'         => $userId,
                'reason'          => Handoff::MOTIVO_AUTODERIVADO,
                'state'           => 'tomado',
                'opened_at'       => now(),
                'taken_at'        => now(),
                'abierto_uid'     => Handoff::VIGENTE,
            ]);
        } catch (QueryException $e) {
            // Dos mensajes del asesor casi simultáneos: el índice único evitó
            // la derivación duplicada y la que ganó ya es suya.
            if ($this->abiertoDe($conversationId)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Cierra el handoff y devuelve la conversación al agente.
     *
     * `$cerradoPor` es null cuando no cierra una persona: ahí `$motivo` dice si
     * fue el sistema por abandono o el agente. Sin esa distinción, «cerrada»
     * tapaba por igual a la que se atendió y a la que nadie contestó.
     */
    public function cerrar(int $conversationId, ?int $cerradoPor = null, string $motivo = Handoff::POR_ASESOR): ?Handoff
    {
        $handoff = $this->abiertoDe($conversationId);

        $handoff?->update([
            'state'         => 'cerrado',
            'closed_at'     => now(),
            'abierto_uid'   => null,
            'cerrado_uid'   => $cerradoPor,
            'cierre_motivo' => $motivo,
        ]);

        return $handoff;
    }

    /**
     * Derivaciones que nadie contestó y ya pasaron el plazo.
     *
     * El filtro por tiempo va en SQL y el de «sin respuesta» en PHP: son pocas
     * filas vigentes, y expresar en SQL «ningún mensaje humano posterior a
     * opened_at» cuesta más de lo que ahorra.
     *
     * @return \Illuminate\Support\Collection<int, Handoff>
     */
    public function abandonadas(int $horas)
    {
        return $this->model->newQuery()
            ->with('conversation')
            ->whereIn('state', ['abierto', 'tomado'])
            ->where('opened_at', '<=', now()->subHours($horas))
            ->get()
            ->filter(fn (Handoff $h) => $h->sinRespuestaHumana())
            ->values();
    }
}
