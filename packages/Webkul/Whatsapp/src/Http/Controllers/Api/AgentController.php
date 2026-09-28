<?php

namespace Webkul\Whatsapp\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
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
     *         @OA\Property(property="reason", type="string", nullable=true, example="El cliente pide hablar con una persona"),
     *         @OA\Property(property="sku", type="string", nullable=true, example="CM_00003", description="Producto de la consulta: con ciudad, elige al asesor por turno"),
     *         @OA\Property(property="ciudad", type="string", nullable=true, example="Santa Cruz")
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
            'sku'    => 'nullable|string|max:60',
            'ciudad' => 'nullable|string|max:60',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        /*
        | Con producto y ciudad, el asesor sale de la matriz por turno (round
        | robin). Sin ellos —o si nadie atiende esa combinación— se enruta como
        | siempre: dueño del lead, del contacto, o el pozo.
        */
        $asignarA = null;

        if (! empty($data['sku'])) {
            $product = app(\Webkul\Product\Repositories\ProductRepository::class)->findOneByField('sku', $data['sku']);

            if ($product) {
                $asignarA = app(\Webkul\Product\Services\Responsables::class)
                    ->asignar($conversation, $product, $data['ciudad'] ?? null)?->id;
            }
        }

        // actorUserId stays null on purpose: the sanctum token belongs to a real
        // user, but the actor here is the bot. Crediting the token's owner would
        // put a human's name on the lead timeline for something they never did.
        $result = $this->handoff->request(
            conversation: $conversation,
            reason: $data['reason'] ?? null,
            source: 'ai',
            actorUserId: null,
            assignTo: $asignarA,
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

        if ($repetido = $this->repiteElInteractivo($conversation, $data['text'])) {
            return $repetido;
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

    /**
     * Rechaza el texto que repite el menú interactivo recién enviado.
     *
     * El modelo tiende a hacer las dos cosas en el mismo turno: llama a
     * `mostrar_opciones` y ADEMÁS escribe la pregunta con las opciones en su
     * respuesta de texto. El cliente recibe el mismo mensaje dos veces seguidas,
     * uno con botones y otro sin ellos, y el segundo lo invita a tipear justo lo
     * que los botones ya resolvían.
     *
     * Se corta acá y no solo en el prompt porque un prompt se olvida: esto es
     * una propiedad de la conversación —no mandar lo mismo dos veces— y el CRM
     * es quien puede garantizarla. El 409 lleva el motivo para que el agente lo
     * entienda en el mismo turno en vez de reintentar.
     *
     * Solo mira el ÚLTIMO mensaje saliente y solo dentro de la ventana de
     * config: dos preguntas iguales separadas por la respuesta del cliente son
     * legítimas y no se tocan.
     */
    protected function repiteElInteractivo($conversation, string $texto): ?JsonResponse
    {
        $segundos = (int) config('whatsapp.antiduplicado_segundos', 120);

        if ($segundos <= 0) {
            return null;
        }

        $ultimo = MessageProxy::modelClass()::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        if (! $ultimo
            || $ultimo->type !== 'interactive'
            || ! $ultimo->created_at
            || $ultimo->created_at->lt(now()->subSeconds($segundos))
        ) {
            return null;
        }

        $menu = static::comparable((string) $ultimo->body);
        $nuevo = static::comparable($texto);

        if ($menu === '' || ! str_starts_with($nuevo, $menu)) {
            return null;
        }

        return response()->json([
            'message' => 'Ya enviaste ese mensaje con botones hace un momento. No lo repitas como texto: '
                .'el cliente lo recibiría dos veces y el segundo lo invita a escribir lo que los botones ya resuelven. '
                .'Esperá su elección.',
            'duplicado_de' => $ultimo->id,
        ], 409);
    }

    /**
     * Forma canónica para comparar dos cuerpos: sin espacios de más ni
     * mayúsculas.
     *
     * El texto del modelo repite la pregunta con otro espaciado y con las
     * opciones agregadas al final; comparar en crudo no encontraría nada.
     */
    protected static function comparable(string $texto): string
    {
        return trim(mb_strtolower(preg_replace('/\s+/u', ' ', $texto) ?? ''));
    }

    /**
     * Botones de respuesta o lista de opciones.
     *
     * Sirve para que el cliente elija sin tipear: un menú de qué material
     * quiere ver, una lista de productos, una confirmación de sí/no.
     *
     * Cuando el cliente elige, su respuesta vuelve como un mensaje entrante
     * normal, con el título en `text` y el `id` en `message.selection.id`. El
     * agente debe rutear por el id: los títulos se acortan a 20/24 caracteres y
     * pueden repetirse entre menús.
     */
    public function sendInteractive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'format'      => ['required', Rule::in(['button', 'list'])],
            'body'        => 'required|string',
            'header'      => 'nullable|string',
            'footer'      => 'nullable|string',
            'buttons'     => 'required_if:format,button|array',
            'list_button' => 'required_if:format,list|nullable|string',
            'sections'    => 'required_if:format,list|array',
            'reply_to_id' => 'nullable|integer',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        if ($conversation->handoffOpen() || ! $conversation->aiEffective()) {
            return response()->json([
                'message' => 'Esta conversación fue derivada a un asesor. El agente ya no debe responder.',
                'handoff' => $this->handoff->toAgentArray($conversation),
            ], 409);
        }

        if (! $this->gateways->for($conversation)->capabilities()->canSend('interactive')) {
            /*
            | 501 y no 422: no es un error del agente ni algo que reintentar.
            | El canal activo —Kommo, Messenger— simplemente no tiene botones.
            */
            return response()->json([
                'message' => 'Este canal no puede enviar botones ni listas. Mandá las opciones como texto numerado.',
            ], 501);
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

        $interactive = OutboundInteractive::fromArray($data);

        /*
        | Se valida ANTES de encolar. Meta rechaza un interactivo pasado de
        | largo con un 400 genérico que no dice qué campo fue: para el agente
        | sería un fallo mudo tres segundos después de haber dicho "elegí una
        | opción". Acá se entera en el mismo turno y con el campo nombrado.
        */
        if ($problema = $interactive->problema()) {
            return response()->json(['message' => $problema], 422);
        }

        $message = MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'interactive',
            'body'            => $interactive->label(),
            'reply_to_id'     => $data['reply_to_id'] ?? null,
            'status'          => 'queued',
            'sender'          => 'ia',
            'payload'         => ['interactive' => $interactive->toArray()],
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json([
            'message' => ['id' => $message->id, 'status' => $message->status, 'sender' => $message->sender],
        ]);
    }
}
