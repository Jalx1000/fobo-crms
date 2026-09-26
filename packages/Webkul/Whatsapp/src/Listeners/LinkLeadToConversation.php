<?php

namespace Webkul\Whatsapp\Listeners;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Enlaza un pedido recién creado con la conversación de WhatsApp de ese contacto.
 *
 * Sin esto, `conversations.lead_id` sólo se escribía al crear la conversación —o
 * sea, únicamente si la persona ya tenía un pedido en ese instante—. Cualquier
 * pedido creado después quedaba suelto: la conv#968 nació el 15/09 sin pedido y
 * el #2563, del 23/09, nunca se enlazó.
 *
 * Lo que rompe un `lead_id` vacío:
 *
 *   - desde el módulo de WhatsApp no se ve a qué pedido pertenece la charla;
 *   - `contact.lead_id` del payload va en null, así que el agente cree que no
 *     hay pedido y hace POST: otro duplicado;
 *   - el filtro por ciudad pierde su último escalón (ConversationCity cae en
 *     cascada hasta el pipeline del pedido).
 *
 * Sólo completa lo que está vacío: una conversación ya enlazada no se repunta a
 * un pedido nuevo, porque un contacto puede tener varios y el vínculo de la
 * conversación es con el primero que se abrió.
 */
class LinkLeadToConversation
{
    public function handle($lead): void
    {
        try {
            if (! $lead || ! $lead->person_id) {
                return;
            }

            ConversationProxy::modelClass()::query()
                ->where('person_id', $lead->person_id)
                ->whereNull('lead_id')
                ->update(['lead_id' => $lead->id]);
        } catch (Throwable $e) {
            /*
             * Nunca puede costar la creación del pedido: esto corre dentro de
             * lead.create.after, y un fallo acá haría fracasar un POST que el
             * agente da por bueno.
             */
            Log::warning('WhatsApp: no se pudo enlazar el pedido a la conversación', [
                'lead_id' => $lead->id ?? null,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
