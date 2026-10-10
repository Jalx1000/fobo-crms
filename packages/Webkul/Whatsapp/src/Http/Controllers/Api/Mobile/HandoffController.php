<?php

namespace Webkul\Whatsapp\Http\Controllers\Api\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Whatsapp\Http\Controllers\InboxController;

/**
 * Quién atiende la conversación: el agente IA o una persona.
 *
 * El actor de todo esto —en la auditoría, en "asignada a"— es el usuario del
 * token. Es distinto de la API del agente, donde el token es de un usuario
 * pero el actor es el bot.
 */
class HandoffController extends MobileController
{
    /**
     * @OA\Patch(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/agent",
     *     operationId="mobileWhatsappToggleAgent",
     *     tags={"WhatsApp Mobile"},
     *     summary="Encender o apagar el agente IA en la conversación",
     *     description="Apagado, el agente deja de responder y el asesor puede escribir. `effective` es lo que rige: con una derivación abierta el agente calla aunque `enabled` sea true.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"enabled"},
     *
     *         @OA\Property(property="enabled", type="boolean", example=false)
     *     )),
     *
     *     @OA\Response(response=200, description="Estado del agente", @OA\JsonContent(
     *
     *         @OA\Property(property="agent", ref="#/components/schemas/MobileAgentState")
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/MobileUnprocessable")
     * )
     */
    public function agent(Request $request, string $id): JsonResponse
    {
        $this->conversation($id);

        return $this->delegate(InboxController::class, 'toggleAgent', ['request' => $request, 'id' => $id]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/handoff/claim",
     *     operationId="mobileWhatsappClaimHandoff",
     *     tags={"WhatsApp Mobile"},
     *     summary="Tomar la conversación",
     *     description="Se asigna al usuario del token y apaga el agente. 409 si otro asesor ya la tomó.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Derivación tomada", @OA\JsonContent(
     *
     *         @OA\Property(property="handoff", ref="#/components/schemas/MobileHandoff")
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=409, ref="#/components/responses/MobileConflict")
     * )
     */
    public function claim(string $id): JsonResponse
    {
        $this->conversation($id);

        return $this->delegate(InboxController::class, 'claimHandoff', ['id' => $id]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/handoff/resolve",
     *     operationId="mobileWhatsappResolveHandoff",
     *     tags={"WhatsApp Mobile"},
     *     summary="Soltar la conversación (cerrar la derivación)",
     *     description="Con `reactivate_ai: true` el agente IA vuelve a atender.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=false, @OA\JsonContent(
     *
     *         @OA\Property(property="reactivate_ai", type="boolean", example=true)
     *     )),
     *
     *     @OA\Response(response=200, description="Derivación cerrada", @OA\JsonContent(
     *
     *         @OA\Property(property="handoff", ref="#/components/schemas/MobileHandoff"),
     *         @OA\Property(property="agent", ref="#/components/schemas/MobileAgentState")
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=409, ref="#/components/responses/MobileConflict")
     * )
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $this->conversation($id);

        return $this->delegate(InboxController::class, 'resolveHandoff', ['request' => $request, 'id' => $id]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/notes",
     *     operationId="mobileWhatsappStoreNote",
     *     tags={"WhatsApp Mobile"},
     *     summary="Dejar una nota interna",
     *     description="No se le envía al cliente. Queda en la línea de tiempo del contacto y del lead en el CRM, y aparece en el hilo con `kind: note`. 422 si la conversación no tiene contacto ni lead vinculado.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"comment"},
     *
     *         @OA\Property(property="comment", type="string", example="Pidió que lo llamen después de las 18 h")
     *     )),
     *
     *     @OA\Response(response=200, description="Nota creada", @OA\JsonContent(
     *
     *         @OA\Property(property="note", ref="#/components/schemas/MobileNote")
     *     )),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound"),
     *     @OA\Response(response=422, ref="#/components/responses/MobileUnprocessable")
     * )
     */
    public function note(Request $request, string $id): JsonResponse
    {
        $this->conversation($id);

        return $this->delegate(InboxController::class, 'storeNote', ['request' => $request, 'id' => $id]);
    }
}
