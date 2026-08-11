<?php

namespace Webkul\Whatsapp\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Token-authenticated API for the external AI agent: read the conversation for
 * context and post replies. Auth is a user API key (Bearer / Sanctum).
 */
class AgentController extends Controller
{
    public function __construct(
        protected GatewayManager $gateways,
        protected AgentPayload $payloads,
        protected Handoff $handoff,
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
            // Lets the workflow check before asking for a handoff again, which
            // is what keeps an eager LLM from mailing the team on every message.
            'handoff'         => $this->handoff->toAgentArray($conversation),
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/whatsapp/conversations/{id}/handoff",
     *     operationId="whatsappAgentHandoff",
     *     tags={"WhatsApp"},
     *     summary="Hand the conversation over to a human advisor",
     *     security={ {"sanctum_admin": {} }},
     *
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(@OA\JsonContent(
     *
     *         @OA\Property(property="reason", type="string", nullable=true, example="El cliente pide hablar con una persona")
     *     )),
     *
     *     @OA\Response(response=200, description="Handoff open; the agent must stop replying"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Conversation not found")
     * )
     *
     * Always 200, never 409 on a repeat call: for n8n a second request is a
     * successful no-op, and an error status would just make the workflow retry
     * in a loop.
     */
    public function handoff(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        // actorUserId stays null on purpose: the sanctum token belongs to a real
        // user, but the actor here is the bot. Crediting the token's owner would
        // put a human's name on the lead timeline for something they never did.
        $result = $this->handoff->request(
            conversation: $conversation,
            reason: $data['reason'] ?? null,
            source: 'ai',
            actorUserId: null,
        );

        return response()->json([
            'handoff' => $this->handoff->toAgentArray($result['conversation'], $result['changed']),
            'agent'   => [
                'enabled'   => $result['conversation']->ai_enabled,
                'effective' => $result['conversation']->aiEffective(),
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

        // The agent's reply travels a long way: CRM → webhook → n8n thinks →
        // POST back here. A handoff can happen anywhere in that gap, so refuse
        // the late reply instead of letting the AI talk over the advisor. This
        // is the mirror image of the guard InboxController::send() already has.
        if ($conversation->handoffOpen() || ! $conversation->aiEffective()) {
            return response()->json([
                'message' => 'Esta conversación fue derivada a un asesor. El agente ya no debe responder.',
                'handoff' => $this->handoff->toAgentArray($conversation),
            ], 409);
        }

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
