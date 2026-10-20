<?php

namespace Webkul\Distribuidor\Listeners;

use Webkul\Distribuidor\Services\Reparto;

/**
 * Deja cada pedido a nombre del distribuidor que le corresponde.
 *
 * 🔴 El problema original: `LeadRepository::create()` asigna el pedido a quien esté
 * autenticado. Cuando lo crea el agente de IA por la API, el autenticado es el DUEÑO
 * DEL TOKEN —un administrador—, así que el pedido quedaba a nombre de un admin y no
 * aparecía en la lista de quien tiene que salir a entregarlo.
 *
 * Con varios distribuidores por ciudad (cada uno una empresa), "el de la zona" ya no
 * alcanza: el pedido va al dueño del cliente, y un cliente nuevo se reparte por
 * turno. Las reglas viven en Reparto; esto sólo las engancha al único punto por el
 * que pasan todos los caminos de alta.
 *
 * El agente ya no elige distribuidor: si manda un user_id se ignora, porque lo
 * decide el turno.
 */
class AsignarDistribuidorDeZona
{
    public function __construct(
        protected Reparto $reparto,
    ) {}

    public function handle($lead): void
    {
        if (! $lead || ! $lead->exists) {
            return;
        }

        /*
         * Reparto escribe la columna directo y no con $lead->save(): esto corre DENTRO
         * del sincronizador de ciudad, y un guardado completo volvería a lanzar toda la
         * cadena por un cambio de una sola columna.
         */
        $this->reparto->asignarLead($lead);
    }
}
