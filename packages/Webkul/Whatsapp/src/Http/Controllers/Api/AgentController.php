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
use Webkul\Whatsapp\Services\Handoff as HandoffService;
use Webkul\Whatsapp\Services\InteractiveParser;

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
    /**
     * Derivar la conversación a un asesor humano.
     *
     * Idempotente: si ya hay una derivación abierta devuelve 200 con el mismo
     * recurso y `changed: false`, sin re-rutear ni volver a avisar. Un LLM
     * tiende a pedir el handoff en cada mensaje.
     */
    public function handoff(Request $request, string $id, HandoffService $handoff): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
            // El nombre de la ciudad tal como lo dijo el cliente. Se acepta
            // texto o el id del pipeline; la comparación ignora tildes.
            'ciudad' => 'nullable',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $resultado = $handoff->request(
            conversation: $conversation,
            reason: $data['reason'] ?? null,
            source: 'ai',
            // El actor es el bot, aunque el token pertenezca a un usuario real.
            actorUserId: null,
            ciudad: $data['ciudad'] ?? null,
        );

        return response()->json([
            'handoff' => $handoff->toAgentArray($resultado['conversation'], $resultado['changed']),
            'agent'   => [
                'enabled'   => $resultado['conversation']->ai_enabled,
                'effective' => $resultado['conversation']->aiEffective(),
            ],
        ]);
    }

    public function send(Request $request, string $id, InteractiveParser $parser): JsonResponse
    {
        $data = $request->validate([
            'text'        => 'required|string',
            'reply_to_id' => 'nullable|integer',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        /*
         * Derivada = ya no es del agente.
         *
         * 409 y no 200: un 200 le haría creer que le habló al cliente, y se
         * quedaría callado esperando respuesta mientras el asesor atiende. El
         * código de error es lo que le dice que suelte el turno.
         */
        if ($conversation->handoffOpen()) {
            return response()->json([
                'message' => 'Esta conversación fue derivada a un asesor. El agente ya no responde acá.',
                'handoff' => app(HandoffService::class)->toAgentArray($conversation),
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

        /*
         * El agente no manda menús por una API aparte: numera las opciones con
         * el marcador y el CRM las convierte. Si el texto no lo trae —el caso
         * normal— parse() devuelve null y esto sigue siendo un envío de texto.
         */
        $parsed = $parser->parse($data['text']);

        $attributes = [
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'text',
            'body'            => $data['text'],
            'reply_to_id'     => $data['reply_to_id'] ?? null,
            'status'          => 'queued',
            'sender'          => 'ia',
        ];

        if ($parsed?->isInteractive()) {
            $attributes['type'] = 'interactive';
            $attributes['body'] = $parsed->interactive->label();
            $attributes['payload'] = ['interactive' => $parsed->interactive->toArray()];
        } elseif ($parsed) {
            /*
             * Marcó opciones pero no entran en los límites de WhatsApp. Sale
             * como texto con la numeración reescrita a "1." —el cliente recibe
             * un mensaje legible— y el motivo queda guardado.
             *
             * No es un 422: el agente cree que manda texto plano y no sabe que
             * existe esta validación. Un error lo dejaría mudo frente al
             * cliente, que es el fallo que esto viene a arreglar.
             */
            $attributes['body'] = $parsed->fallbackText;
            $attributes['payload'] = ['interactive_skipped' => $parsed->reason];
        }

        $message = MessageProxy::modelClass()::create($attributes);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json([
            'message' => ['id' => $message->id, 'status' => $message->status, 'sender' => $message->sender],
        ]);
    }
}
