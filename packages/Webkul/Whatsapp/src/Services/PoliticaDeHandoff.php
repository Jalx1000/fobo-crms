<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Whatsapp\Models\Handoff;

/**
 * Decide cuándo una conversación derivada vuelve al agente de IA.
 *
 * Existe porque el handoff solo se cerraba por API y nadie llamaba a ese
 * endpoint: una conversación derivada quedaba muda para siempre. El agente no
 * se enteraba de los mensajes entrantes, así que tampoco podía pedir el cierre.
 */
class PoliticaDeHandoff
{
    /**
     * ¿Este handoff ya cumplió su función y hay que devolver el control al agente?
     *
     * Criterio: solo se recupera lo que nadie atendió. Si el handoff sigue en
     * 'abierto' pasado el plazo de escalado, nadie lo va a tomar, y el silencio
     * es peor que una respuesta automática. En cambio 'tomado' significa que un
     * asesor está en la conversación: ahí la IA no interrumpe, porque pisarle
     * una negociación en curso al asesor es un daño mayor que la demora.
     */
    public function debeDevolverAlAgente(Handoff $handoff): bool
    {
        if ($handoff->state !== 'abierto') {
            return false;
        }

        $minutos = (int) config('inmobiliaria.inmuebles.handoff.devolver_tras_minutos', 60);

        return $handoff->opened_at?->addMinutes($minutos)->isPast() ?? false;
    }
}
