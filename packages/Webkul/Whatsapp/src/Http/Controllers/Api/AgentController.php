<?php

namespace Webkul\Whatsapp\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Repositories\HandoffRepository;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\OutboundDelivery;
use Webkul\Whatsapp\Services\PhoneNumber;

/**
 * Token-authenticated API for the external AI agent: read the conversation for
 * context and post replies. Auth is a user API key (Bearer / Sanctum).
 */
class AgentController extends Controller
{
    public function __construct(
        protected GatewayManager $gateways,
        protected AgentPayload $payloads,
        protected OutboundDelivery $delivery,
        protected HandoffRepository $handoffs,
    ) {}

    /**
     * @OA\Get(
     *     path="/api/v1/whatsapp/conversations/{id}/messages",
     *     operationId="whatsappConversationMessages",
     *     tags={"WhatsApp"},
     *     summary="Get a WhatsApp conversation's recent messages (agent context)",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Conversation contact + history"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Conversation not found")
     * )
     */
    public function messages(string $id): JsonResponse
    {
        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        return response()->json([
            'conversation_id' => $conversation->id,
            'gateway'         => $conversation->gateway,
            'contact'         => $this->payloads->contact($conversation),
            'history'         => $this->payloads->history($conversation),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/whatsapp/conversations/{id}/messages",
     *     operationId="whatsappAgentReply",
     *     tags={"WhatsApp"},
     *     summary="Send a WhatsApp reply as the AI agent",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"text"},
     *
     *         @OA\Property(property="text", type="string", example="Hola, ¿en qué te ayudo?"),
     *         @OA\Property(property="reply_to_id", type="integer", nullable=true)
     *     )),
     *
     *     @OA\Response(response=200, description="Message queued for delivery"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Conversation not found"),
     *     @OA\Response(response=422, description="Outside the 24h messaging window")
     * )
     */
    public function send(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'text'        => 'required|string',
            'reply_to_id' => 'nullable|integer',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        // 409 = un humano tomó la conversación · 422 = se cerró la ventana 24h.
        // Son bloqueos distintos y el agente reacciona distinto a cada uno.
        if ($bloqueo = $this->delivery->bloqueoPara($conversation)) {
            return response()->json(['message' => $bloqueo[1]], $bloqueo[0]);
        }

        $message = $this->delivery->texto($conversation, $data['text'], $data['reply_to_id'] ?? null);

        return response()->json([
            'message' => ['id' => $message->id, 'status' => $message->status, 'sender' => $message->sender],
        ]);
    }

    /**
     * Guarda el teléfono que el cliente escribió (captura en Messenger, §7).
     *
     * El agente manda el número tal cual lo tipeó la persona; la normalización y
     * la validación son responsabilidad del CRM, que es quien sabe cómo se
     * marcan los números en esta plaza.
     */
    public function telefono(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'telefono'  => 'nullable|string|max:40',
            'rechazado' => 'nullable|boolean',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        // El rechazo también se registra: es información comercial (esta persona
        // no quiere dar el número) y además frena futuros pedidos.
        if ($request->boolean('rechazado')) {
            $conversation->forceFill([
                'phone_rechazado'       => true,
                'phone_prompt_attempts' => $conversation->phone_prompt_attempts + 1,
            ])->save();

            return response()->json(['message' => 'Rechazo registrado.', 'phone_prompt_exhausted' => true]);
        }

        $normalizado = PhoneNumber::normalize((string) ($data['telefono'] ?? ''));

        if (blank($normalizado)) {
            // 422 y no 400: el agente lo reintenta pidiéndoselo de nuevo al
            // cliente una vez, según su propio contrato.
            $conversation->increment('phone_prompt_attempts');

            return response()->json(['message' => 'El número no es válido.'], 422);
        }

        $conversation->forceFill(['wa_phone' => $normalizado])->save();

        return response()->json(['message' => 'Teléfono guardado.', 'telefono' => $normalizado]);
    }

    /**
     * Qué hizo antes este contacto, para que el agente no repregunte.
     */
    public function historial(string $id): JsonResponse
    {
        $conversation = ConversationProxy::modelClass()::with(['person', 'lead'])->findOrFail($id);

        return response()->json($this->payloads->historial($conversation));
    }

    /**
     * Cierra el handoff y devuelve la conversación al agente.
     *
     * No lo llama el agente —él solo deriva— sino el CRM cuando el asesor da por
     * terminada la atención. Existe acá para que el ciclo sea completo y
     * auditable desde un solo lugar.
     */
    public function cerrarHandoff(string $id): JsonResponse
    {
        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $handoff = $this->handoffs->cerrar($conversation->id);

        return response()->json([
            'message' => $handoff ? 'Handoff cerrado.' : 'No había handoff abierto.',
            'handoff' => $this->payloads->handoff($conversation),
        ]);
    }
}
