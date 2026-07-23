<?php

namespace Webkul\Whatsapp\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * Token-authenticated API for the external AI agent: read the conversation for
 * context and post replies. Auth is a user API key (Bearer / Sanctum).
 */
class AgentController extends Controller
{
    public function __construct(
        protected GatewayManager $gateways,
        protected AgentPayload $payloads,
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
     * Derivación a recepción (CONTRATO B).
     *
     * El agente pide el traspaso y el CRM apaga el bot para esa conversación.
     * Va atado a la CONVERSACIÓN —o sea, al paciente— y no a un lead: los leads
     * son las citas, van cambiando de etapa, y mover una a "recepción" le
     * borraría su estado real. El paciente, en cambio, es estable.
     *
     * El agente ya recibe `conversation_id` en cada evento, así que no tiene
     * nada que resolver: una sola llamada con un dato que ya tiene.
     *
     * @OA\Post(
     *     path="/api/v1/whatsapp/conversations/{id}/handoff",
     *     operationId="whatsappAgentHandoff",
     *     tags={"WhatsApp"},
     *     summary="Derivar la conversación a una recepcionista humana",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(@OA\JsonContent(
     *         @OA\Property(property="motivo", type="string", nullable=true),
     *         @OA\Property(property="fuera_de_horario", type="boolean", nullable=true)
     *     )),
     *
     *     @OA\Response(response=200, description="Conversación derivada"),
     *     @OA\Response(response=404, description="Conversation not found")
     * )
     */
    public function handoff(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'motivo'           => 'nullable|string|max:500',
            'fuera_de_horario' => 'nullable|boolean',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $conversation->update([
            'ai_enabled'     => false,
            // Sella el traspaso: es lo que mide whatsapp:reactivate-agent.
            'ai_disabled_at' => now(),
            // Y lo marca como pedido POR EL PACIENTE, que espera su propio plazo.
            'ai_handoff_at'  => now(),
        ]);

        Log::info('WhatsApp: el agente derivó la conversación a recepción', [
            'conversation_id'  => $conversation->id,
            'person_id'        => $conversation->person_id,
            'motivo'           => $data['motivo'] ?? null,
            'fuera_de_horario' => (bool) ($data['fuera_de_horario'] ?? false),
        ]);

        return response()->json([
            'handoff' => [
                'conversation_id' => $conversation->id,
                'ai_enabled'      => $conversation->ai_enabled,
                'derivada_at'     => optional($conversation->ai_disabled_at)->toIso8601String(),
            ],
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

        $window = MessagingWindow::compute(
            $this->gateways->for($conversation)->messagingWindowHours(),
            $conversation->last_inbound_at,
        );

        if ($window->applies && ! $window->open) {
            return response()->json([
                'message' => 'Fuera de la ventana de 24h de WhatsApp. Se requiere una plantilla aprobada o esperar a que el cliente escriba.',
            ], 422);
        }

        $message = MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'text',
            'body'            => $data['text'],
            'reply_to_id'     => $data['reply_to_id'] ?? null,
            'status'          => 'queued',
            'sender'          => 'ia',
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json([
            'message' => ['id' => $message->id, 'status' => $message->status, 'sender' => $message->sender],
        ]);
    }
}
