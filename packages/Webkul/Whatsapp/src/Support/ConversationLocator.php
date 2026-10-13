<?php

namespace Webkul\Whatsapp\Support;

use Illuminate\Http\Request;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\PhoneNumber;

/**
 * A qué conversación se refiere un request.
 *
 * El inbox se abre de dos maneras y hay que soportar las dos: la página de chat
 * manda `conversation_id`, y el inbox embebido en la ficha del contacto, del lead y
 * en el Punto manda `person_id` / `lead_id` / `phone`, porque desde ahí todavía no
 * se sabe qué conversación es.
 *
 * Vive acá y no dentro del controlador porque el guard de alcance por ciudad
 * necesita responder la MISMA pregunta antes de dejar pasar el request. Mientras
 * estuvo sólo en el controlador, el guard no podía resolver los requests sin
 * `conversation_id` y los dejaba pasar sin validar nada: alcanzaba con mandar el
 * teléfono para leer la conversación de cualquier cliente de cualquier ciudad.
 *
 * Dos copias de esta cascada serían peor que ninguna: en cuanto se separen, el
 * guard valida una conversación y el controlador devuelve otra.
 */
final class ConversationLocator
{
    /**
     * La conversación que el request identifica, o null si todavía no existe.
     *
     * Null es un caso normal, no un error: escribirle por primera vez a un teléfono
     * nuevo no tiene conversación hasta que se manda el mensaje.
     */
    public static function desde(Request $request)
    {
        $model = ConversationProxy::modelClass();

        // La página de chat direcciona la conversación por id.
        if ($conversationId = $request->input('conversation_id')) {
            return $model::find($conversationId);
        }

        /*
         * person_id y lead_id antes que el teléfono: el vínculo se guarda al
         * ingestar el mensaje entrante y es confiable, mientras que el teléfono
         * puede estar guardado en otro formato que el "from" de Meta.
         */
        if ($personId = $request->input('person_id')) {
            if ($conversation = self::preferirGatewayActivo($model::where('person_id', $personId))->first()) {
                return $conversation;
            }
        }

        if ($leadId = $request->input('lead_id')) {
            if ($conversation = self::preferirGatewayActivo($model::where('lead_id', $leadId))->first()) {
                return $conversation;
            }
        }

        if ($phone = $request->input('phone')) {
            return $model::where('wa_phone', PhoneNumber::normalize($phone))->first();
        }

        return null;
    }

    /**
     * Un contacto puede terminar con varias conversaciones —una por proveedor
     * después de una migración, o una por formato de teléfono—. Se devuelve la del
     * gateway activo: es la única por la que el asesor puede responder de verdad.
     * Ordenar sólo por fecha traería una conversación muerta de un proveedor dado
     * de baja.
     */
    protected static function preferirGatewayActivo($query)
    {
        return $query
            ->orderByRaw('gateway = ? desc', [(string) config('whatsapp.gateway')])
            ->orderByDesc('last_message_at');
    }
}
