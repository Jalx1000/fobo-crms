<?php

namespace Webkul\Whatsapp\Http\Controllers\Api\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Whatsapp\Http\Controllers\ChatController;
use Webkul\Whatsapp\Http\Controllers\InboxController;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;

class ConversationController extends MobileController
{
    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/conversations",
     *     operationId="mobileWhatsappConversations",
     *     tags={"WhatsApp Mobile"},
     *     summary="Listado de conversaciones (bandeja)",
     *     description="Paginado, una fila por contacto (las conversaciones duplicadas de un mismo número se unifican). Orden: primero las derivadas sin asesor, luego las derivadas, luego por último mensaje. Sólo incluye las conversaciones que el usuario puede ver según su `view_permission`.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="filter", in="query", required=false, description="unassigned = sin contacto ni lead · unread = con no leídos · handoff = derivadas · pool = derivadas sin asesor · mine = derivadas a mí", @OA\Schema(type="string", enum={"unassigned", "unread", "handoff", "pool", "mine"})),
     *     @OA\Parameter(name="search", in="query", required=false, description="Nombre de WhatsApp, teléfono o nombre del contacto", @OA\Schema(type="string")),
     *     @OA\Parameter(name="city", in="query", required=false, description="Id de ciudad (de /cities) o `none` para sin ciudad", @OA\Schema(type="string", example="3")),
     *     @OA\Parameter(name="stage", in="query", required=false, description="Nombre de etapa (de /stages)", @OA\Schema(type="string", example="No atendido")),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="per_page", in="query", required=false, description="Entre 30 y 300", @OA\Schema(type="integer", default=30)),
     *
     *     @OA\Response(response=200, description="Página de conversaciones", @OA\JsonContent(ref="#/components/schemas/MobileConversationPage")),
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return $this->delegate(ChatController::class, 'conversations', ['request' => $request]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/conversations/{id}/messages",
     *     operationId="mobileWhatsappThread",
     *     tags={"WhatsApp Mobile"},
     *     summary="Hilo de la conversación (carga inicial o incremental)",
     *     description="Sin `since`: los últimos 200 mensajes en orden cronológico, y marca la conversación como leída. Con `since`: sólo los mensajes nuevos o que cambiaron de estado desde ese momento (enviado → entregado → leído). Para refrescar, guardar `server_time` de cada respuesta y mandarlo como `since` en la siguiente; mezclar por `id` (es idempotente). Incluye las notas internas (`kind: note`).",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="since", in="query", required=false, description="`server_time` de la respuesta anterior", @OA\Schema(type="string", example="2026-10-10 14:03:22")),
     *
     *     @OA\Response(response=200, description="Hilo", @OA\JsonContent(ref="#/components/schemas/MobileThread")),
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden"),
     *     @OA\Response(response=404, ref="#/components/responses/MobileNotFound")
     * )
     */
    public function messages(Request $request, string $id): JsonResponse
    {
        $conversation = $this->conversation($id);

        return $this->delegate(InboxController::class, 'thread', [
            'request' => $this->forConversation($request, $conversation),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/mobile/whatsapp/unread-count",
     *     operationId="mobileWhatsappUnreadCount",
     *     tags={"WhatsApp Mobile"},
     *     summary="Contadores para el badge de la app",
     *     description="Sobre las conversaciones que el usuario puede ver. Liviano: pensado para consultarse seguido.",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Contadores",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="conversations", type="integer", description="Conversaciones con al menos un mensaje sin leer", example=4),
     *                 @OA\Property(property="messages", type="integer", description="Total de mensajes sin leer", example=11),
     *                 @OA\Property(property="pool", type="integer", description="Derivadas sin asesor: alguien espera y nadie la tomó", example=1),
     *                 @OA\Property(property="mine", type="integer", description="Derivaciones abiertas asignadas a mí", example=2)
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, ref="#/components/responses/MobileUnauthenticated"),
     *     @OA\Response(response=403, ref="#/components/responses/MobileForbidden")
     * )
     */
    public function unreadCount(): JsonResponse
    {
        $visibles = fn () => ConversationProxy::modelClass()::query()->visibleToCurrentUser();

        $noLeidas = $visibles()->where('unread_count', '>', 0)
            ->selectRaw('count(*) as conversaciones, coalesce(sum(unread_count), 0) as mensajes')
            ->first();

        return response()->json([
            'data' => [
                'conversations' => (int) $noLeidas->conversaciones,
                'messages'      => (int) $noLeidas->mensajes,
                'pool'          => $visibles()
                    ->where('handoff_state', Conversation::HANDOFF_REQUESTED)
                    ->whereNull('assigned_user_id')
                    ->count(),
                'mine'          => $visibles()
                    ->whereIn('handoff_state', [Conversation::HANDOFF_REQUESTED, Conversation::HANDOFF_ASSIGNED])
                    ->where('assigned_user_id', auth()->guard('user')->id())
                    ->count(),
            ],
        ]);
    }
}
