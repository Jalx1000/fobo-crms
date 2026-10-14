<?php

namespace Webkul\Distribuidor\Listeners;

use Illuminate\Support\Facades\Log;
use Webkul\Distribuidor\Services\AsignacionDeZona;

/**
 * Deja cada pedido a nombre del distribuidor que reparte en su ciudad.
 *
 * 🔴 El problema que resuelve: `LeadRepository::create()` asigna el pedido a quien esté
 * autenticado. Cuando lo crea el agente de IA por la API, el autenticado es el DUEÑO
 * DEL TOKEN —un administrador—, así que el pedido quedaba a nombre de un admin y no
 * aparecía en la lista de quien tiene que salir a entregarlo.
 *
 * Quién asigna, en orden: el AGENTE manda el `user_id` que decidió, y esto lo VALIDA.
 * No es redundancia. El agente es un modelo de lenguaje eligiendo un id numérico: si se
 * equivoca, el pedido igual existe, igual tiene dueño, y le aparece a la persona
 * equivocada sin que nadie se entere. Acá se corrige y queda en el log.
 *
 * Además el agente no es el único que crea pedidos —están el formulario web, el kanban
 * y el Punto—, y esos caminos nunca van a leer el prompt del agente.
 */
class AsignarDistribuidorDeZona
{
    public function __construct(
        protected AsignacionDeZona $zonas,
    ) {}

    public function handle($lead): void
    {
        if (! $lead || ! $lead->exists) {
            return;
        }

        $correcto = $this->zonas->asesorQueCorresponde($lead);

        // null significa "no hay nada que cambiar": o ya está bien, o esa ciudad no
        // tiene distribuidor cargado. Dejarlo sin dueño es mejor que inventar uno.
        if (! $correcto) {
            return;
        }

        $anterior = $lead->user_id ? (int) $lead->user_id : null;

        /*
         * Se escribe la columna directo y no con $lead->save().
         *
         * save() dispara los eventos de guardado del lead, y esto corre DENTRO del
         * sincronizador de ciudad: un guardado completo acá vuelve a lanzar toda la
         * cadena por un cambio de una sola columna. Lo que cambia es el dueño, nada más.
         */
        $lead->newQuery()->whereKey($lead->id)->update(['user_id' => $correcto]);

        // Y en memoria, para que quien tenga el objeto no siga leyendo el valor viejo.
        $lead->user_id = $correcto;

        /*
         * Queda registrado SIEMPRE que se corrige, con el valor anterior.
         *
         * Es el único rastro de que el agente mandó un asesor equivocado. Sin esto, la
         * corrección es invisible y nadie se entera de que el prompt quedó desfasado
         * —por ejemplo, cuando entra un distribuidor nuevo y el agente sigue mandando
         * el id del anterior—.
         */
        Log::info('Pedido reasignado al distribuidor de la zona', [
            'lead_id'     => $lead->id,
            'pipeline_id' => $lead->lead_pipeline_id,
            'antes'       => $anterior,
            'ahora'       => $correcto,
        ]);
    }
}
