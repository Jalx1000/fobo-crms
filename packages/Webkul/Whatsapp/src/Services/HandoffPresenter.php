<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Whatsapp\Models\Handoff;

/**
 * El estado de la derivación, con la forma que consume el inbox.
 *
 * Vive aparte del payload del agente a propósito: el inbox necesita el nombre
 * del asesor para mostrarlo, y el agente tiene su propio esquema acordado. Un
 * único método sirviendo a los dos terminaría con uno de los dos consumidores
 * roto el día que el otro pida un campo más.
 */
class HandoffPresenter
{
    /**
     * @return array{state: string, open: bool, tomado: bool, ...}
     */
    public function paraInbox(?Handoff $handoff, ?int $viewerId = null): array
    {
        // Todas las claves siempre presentes, nulls incluidos: el front asigna
        // este objeto entero en cada poll, y una clave faltante dejaría en
        // pantalla el valor de la conversación anterior.
        if (! $handoff) {
            return [
                'state'      => 'ninguno',
                'open'       => false,
                'tomado'     => false,
                'reason'     => null,
                'referencia' => null,
                'asesor'     => null,
                'is_mine'    => false,
                'opened_at'  => null,
                'taken_at'   => null,
            ];
        }

        $handoff->loadMissing('asesor:id,name');

        return [
            'state'      => $handoff->state,
            'open'       => $handoff->estaAbierto(),
            'tomado'     => $handoff->state === 'tomado',
            'reason'     => $handoff->reason,
            'referencia' => $handoff->referencia_codigo,
            'asesor'     => $handoff->asesor
                ? ['id' => $handoff->asesor->id, 'name' => $handoff->asesor->name]
                : null,
            // Para que el botón diga "Cerrar" y no "Tomar" cuando ya es tuyo.
            'is_mine'    => $viewerId !== null && (int) $handoff->user_id === (int) $viewerId,
            'opened_at'  => optional($handoff->opened_at)->toIso8601String(),
            'taken_at'   => optional($handoff->taken_at)->toIso8601String(),
        ];
    }
}
