<?php

namespace Webkul\Whatsapp\Http\Controllers\Api\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Whatsapp\Http\Controllers\InboxController;

/**
 * Envío de mensajes desde la app. Todos quedan a nombre del usuario del token
 * (`sender: human`) y salen por la cola: la respuesta trae el mensaje en
 * estado `queued`, y el hilo lo va actualizando a sent/delivered/read.
 *
 * Con el agente IA encendido en la conversación, todos responden 422: hay que
 * apagarlo (PATCH /agent) o tomar la derivación antes de escribir a mano.
 */
class MessageController extends MobileController
{
    /**
     * @OA\Post(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/messages",
     *     operationId="mobileWhatsappSendText",
     *     tags={"WhatsApp Mobile"},
     *     summary="Enviar texto",
     *     description="Sólo dentro de la ventana de 24 h desde el último mensaje del cliente (`window.open` en el hilo). Fuera de la ventana, usar una plantilla.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"text"},
     *
     *         @OA\Property(property="text", type="string", example="Hola, ¿en qué te ayudo?"),
     *         @OA\Property(property="reply_to_id", type="integer", nullable=true, description="Id de un mensaje del hilo, para citarlo")
     *     )),
     *
     *     @OA\Response(response=200, description="Mensaje encolado", @OA\JsonContent(ref="#/components/schemas/MobileSentMessage")),
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/MobileUnprocessable")
     * )
     */
    public function text(Request $request, string $id): JsonResponse
    {
        return $this->delegate(InboxController::class, 'send', [
            'request' => $this->forConversation($request, $this->conversation($id)),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/media",
     *     operationId="mobileWhatsappSendMedia",
     *     tags={"WhatsApp Mobile"},
     *     summary="Enviar imagen, audio, video o documento",
     *     description="multipart/form-data. Máximo 16 MB. Las notas de voz se mandan como `kind=audio`. 501 si el canal activo no envía ese tipo.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(
     *         required={"kind", "file"},
     *
     *         @OA\Property(property="kind", type="string", enum={"image", "document", "audio", "video"}),
     *         @OA\Property(property="file", type="string", format="binary"),
     *         @OA\Property(property="caption", type="string", maxLength=1024, nullable=true, description="No aplica a audio"),
     *         @OA\Property(property="reply_to_id", type="integer", nullable=true)
     *     ))),
     *
     *     @OA\Response(response=200, description="Mensaje encolado", @OA\JsonContent(ref="#/components/schemas/MobileSentMessage")),
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/MobileUnprocessable"),
     *     @OA\Response(response=501, ref="#/components/responses/MobileNotSupported")
     * )
     */
    public function media(Request $request, string $id): JsonResponse
    {
        return $this->delegate(InboxController::class, 'sendMedia', [
            'request' => $this->forConversation($request, $this->conversation($id)),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/location",
     *     operationId="mobileWhatsappSendLocation",
     *     tags={"WhatsApp Mobile"},
     *     summary="Enviar una ubicación",
     *     description="Las sucursales guardadas vienen en `locations` del hilo, listas para mandar.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"latitude", "longitude"},
     *
     *         @OA\Property(property="latitude", type="number", format="float", example=-17.7833),
     *         @OA\Property(property="longitude", type="number", format="float", example=-63.1821),
     *         @OA\Property(property="label", type="string", nullable=true, example="Sucursal Centro"),
     *         @OA\Property(property="address", type="string", nullable=true, example="Av. Monseñor Rivero 123"),
     *         @OA\Property(property="reply_to_id", type="integer", nullable=true)
     *     )),
     *
     *     @OA\Response(response=200, description="Mensaje encolado", @OA\JsonContent(ref="#/components/schemas/MobileSentMessage")),
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/MobileUnprocessable"),
     *     @OA\Response(response=501, ref="#/components/responses/MobileNotSupported")
     * )
     */
    public function location(Request $request, string $id): JsonResponse
    {
        return $this->delegate(InboxController::class, 'sendLocation', [
            'request' => $this->forConversation($request, $this->conversation($id)),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/templates",
     *     operationId="mobileWhatsappTemplates",
     *     tags={"WhatsApp Mobile"},
     *     summary="Plantillas aprobadas por Meta",
     *     description="Si el canal no las soporta o Meta no responde, `templates` llega vacío y `reason` dice por qué (siempre 200).",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Plantillas",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="templates", type="array", @OA\Items(ref="#/components/schemas/MobileTemplate")),
     *             @OA\Property(property="reason", type="string", nullable=true, example=null)
     *         )
     *     ),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden")
     * )
     */
    public function templates(): JsonResponse
    {
        return $this->delegate(InboxController::class, 'templates');
    }

    /**
     * @OA\Post(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/template",
     *     operationId="mobileWhatsappSendTemplate",
     *     tags={"WhatsApp Mobile"},
     *     summary="Enviar una plantilla",
     *     description="La única forma de escribir fuera de la ventana de 24 h. `variables` en orden: {{1}}, {{2}}…; tienen que ser exactamente las que pide la plantilla (`variables` en /templates).",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"template", "language"},
     *
     *         @OA\Property(property="template", type="string", example="seguimiento_pedido"),
     *         @OA\Property(property="language", type="string", example="es"),
     *         @OA\Property(property="variables", type="array", @OA\Items(type="string"), example={"María"})
     *     )),
     *
     *     @OA\Response(response=200, description="Mensaje encolado", @OA\JsonContent(ref="#/components/schemas/MobileSentMessage")),
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/MobileUnprocessable"),
     *     @OA\Response(response=501, ref="#/components/responses/MobileNotSupported")
     * )
     */
    public function template(Request $request, string $id): JsonResponse
    {
        return $this->delegate(InboxController::class, 'sendTemplate', [
            'request' => $this->forConversation($request, $this->conversation($id)),
        ]);
    }
}
