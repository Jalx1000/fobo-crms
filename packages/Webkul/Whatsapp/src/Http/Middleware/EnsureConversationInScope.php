<?php

namespace Webkul\Whatsapp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Core\Services\AlcanceCiudades;
use Webkul\Whatsapp\Support\ConversationCity;

/**
 * Exige que la conversación sea de una ciudad que el usuario puede ver.
 *
 * El permiso "whatsapp" (EnsureWhatsappAccess) decide si entrás al módulo; esto
 * decide QUÉ conversaciones podés tocar dentro. Son dos preguntas distintas: en
 * una instalación con una ciudad por sucursal, todos los Encargados tienen el
 * permiso y ninguno debería leer la conversación de otra ciudad.
 *
 * Acotar la lista no alcanza. La lista se pide por /whatsapp/conversations, pero
 * el hilo se pide por /whatsapp/thread?conversation_id=N: con el id a mano —y los
 * ids son correlativos— se leía cualquier conversación, se le escribía al cliente
 * y se le tomaba la derivación, sin aparecer nunca en la bandeja propia.
 *
 * Una conversación SIN ciudad pasa para todos, igual que en la lista: nadie la
 * tiene asignada, así que es de todos. El motivo largo está en
 * ConversationCity::restringirA().
 *
 * Cuando no hay conversación en el request —enviar a un teléfono que todavía no
 * tiene conversación— no hay nada que validar y pasa de largo.
 */
class EnsureConversationInScope
{
    public function __construct(protected AlcanceCiudades $alcance) {}

    public function handle(Request $request, Closure $next)
    {
        $id = $request->route('id') ?? $request->input('conversation_id');

        if (! $id || ! is_numeric($id)) {
            return $next($request);
        }

        $permitidas = $this->alcance->ciudadesDe();

        if ($permitidas === null) {
            return $next($request);
        }

        $ciudad = ConversationCity::de((int) $id);

        // Null = sin ciudad por ningún camino: pool, la ve cualquiera.
        $puede = $ciudad === null
            || in_array($ciudad, $permitidas, true)
            || in_array($ciudad, ConversationCity::noneEquivalentPipelineIds(), true);

        if ($puede) {
            return $next($request);
        }

        // 403 y no 404: el asesor existe, la conversación existe, lo que falta es
        // el alcance. Un 404 lo mandaría a buscar un id que está bien.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Esta conversación es de otra ciudad.',
            ], 403);
        }

        abort(403, 'Esta conversación es de otra ciudad.');
    }
}
