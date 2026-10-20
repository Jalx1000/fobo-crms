<?php

namespace Webkul\Whatsapp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Core\Services\AlcanceCiudades;
use Webkul\Core\Services\Distribuidores;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Support\ConversationCity;
use Webkul\Whatsapp\Support\ConversationLocator;

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
    public function __construct(
        protected AlcanceCiudades $alcance,
        protected Distribuidores $distribuidores,
    ) {}

    /**
     * La regla del distribuidor: la conversación tiene que ser de un cliente suyo.
     *
     * Sin conversación todavía (escribirle a un número por primera vez), se mira de
     * quién es el número: si es cliente de otro distribuidor, no se le puede escribir.
     */
    protected function delDistribuidor(Request $request, Closure $next, int $yo)
    {
        $id = $this->conversacionDel($request);

        if ($id) {
            $personId = ConversationProxy::modelClass()::whereKey($id)->value('person_id');

            $esSuya = $personId && $this->distribuidores->esDeMiEmpresa($this->distribuidores->duenoDe((int) $personId));

            return $esSuya ? $next($request) : $this->rechazar($request, 'Esta conversación es de otro distribuidor.');
        }

        $personId = $request->input('person_id')
            ?: ($request->input('phone')
                ? app(PersonRepository::class)->findByPhone((string) $request->input('phone'))?->id
                : null);

        if ($personId && ! $this->distribuidores->puedeUsarCliente($yo, (int) $personId)) {
            return $this->rechazar($request, $this->distribuidores->avisoClienteDeOtro((int) $personId));
        }

        return $next($request);
    }

    protected function rechazar(Request $request, string $mensaje)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $mensaje], 403);
        }

        abort(403, $mensaje);
    }

    /**
     * La conversación que el request toca, por cualquiera de sus dos caminos.
     *
     * Antes esto miraba sólo `conversations/{id}` y `conversation_id`, y ahí estaba
     * el agujero: el inbox embebido en la ficha del contacto, del lead y en el Punto
     * NO manda conversation_id —manda person_id, lead_id o phone— así que el guard
     * los dejaba pasar sin validar nada. Alcanzaba con pedir el hilo con el teléfono
     * para leer la conversación de cualquier cliente de cualquier ciudad, escribirle
     * y tomarle la derivación.
     *
     * La resolución por teléfono/persona la hace ConversationLocator, el MISMO
     * resolutor que usa el controlador: si el guard resolviera distinto, validaría
     * una conversación y el controlador devolvería otra.
     */
    protected function conversacionDel(Request $request): ?int
    {
        // conversations/{id}/… — en los grupos donde está este middleware, {id} es
        // siempre una conversación.
        $deLaRuta = $request->route('id');

        if ($deLaRuta && is_numeric($deLaRuta)) {
            return (int) $deLaRuta;
        }

        return ConversationLocator::desde($request)?->id;
    }

    public function handle(Request $request, Closure $next)
    {
        /*
         * Un distribuidor sólo toca las conversaciones de SUS clientes. Va antes que la
         * ciudad y es más estricto: otro distribuidor de su misma ciudad es otra
         * empresa, y una conversación sin cliente (el pool) no es de nadie, así que
         * tampoco es suya.
         */
        if ($yo = $this->distribuidores->soloLoSuyo()) {
            return $this->delDistribuidor($request, $next, $yo);
        }

        $permitidas = $this->alcance->ciudadesDe();

        if ($permitidas === null) {
            return $next($request);
        }

        $id = $this->conversacionDel($request);

        /*
         * Sin conversación no hay nada que validar, y es un caso normal: escribirle
         * por primera vez a un teléfono nuevo no tiene conversación hasta que se
         * manda el mensaje.
         */
        if (! $id) {
            return $next($request);
        }

        $ciudad = ConversationCity::de($id);

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
