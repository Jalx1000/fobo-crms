<?php

namespace Webkul\Whatsapp\Http\Controllers\Api\Mobile;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Base de los controladores de la app.
 *
 * No hay lógica propia: cada endpoint traduce la URL REST de la app
 * (`/conversations/{id}/...`) a lo que espera la bandeja del CRM y delega.
 * Así la web y la app no pueden separarse: es el mismo código.
 *
 * El usuario del token ya está en el guard "user" (AuthenticateMobileUser),
 * así que permisos, visibilidad y autoría salen solos.
 */
abstract class MobileController extends Controller
{
    /**
     * La conversación, si existe y el usuario la puede ver. Si no, 404.
     *
     * Antes de delegar, porque la bandeja resuelve por input y con un id
     * inexistente devuelve 200 con `conversation: null`; la app espera 404.
     */
    protected function conversation(string $id)
    {
        return ConversationProxy::modelClass()::visibleToCurrentUser()->findOrFail($id);
    }

    /**
     * La request con lo que la bandeja usa para encontrar la conversación.
     *
     * `phone` va también porque `send` lo exige; con `conversation_id` la
     * bandeja nunca cae a buscar (ni crear) por teléfono.
     */
    protected function forConversation(Request $request, $conversation): Request
    {
        $request->merge([
            'conversation_id' => $conversation->id,
            'phone'           => $conversation->wa_phone,
        ]);

        return $request;
    }

    /**
     * Llama un método de un controlador de la bandeja con inyección de
     * dependencias, como lo haría el router.
     */
    protected function delegate(string $controller, string $method, array $parameters = [])
    {
        return app()->call([app($controller), $method], $parameters);
    }
}
