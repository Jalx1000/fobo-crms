<?php

namespace Webkul\Whatsapp\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Whatsapp\Exceptions\InteractiveMessageException;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Repositories\HandoffRepository;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\InteractiveParser;
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
    public function send(Request $request, string $id, InteractiveParser $parser): JsonResponse
    {
        // `text` es obligatorio salvo que venga un interactivo: los botones
        // llevan su propio enunciado adentro, en interactive.body.text.
        $data = $request->validate([
            'text'        => 'required_without:interactive|nullable|string',
            'interactive' => 'nullable|array',
            'reply_to_id' => 'nullable|integer',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        // 409 = un humano tomó la conversación · 422 = se cerró la ventana 24h.
        // Son bloqueos distintos y el agente reacciona distinto a cada uno.
        if ($bloqueo = $this->delivery->bloqueoPara($conversation)) {
            return response()->json(['message' => $bloqueo[1]], $bloqueo[0]);
        }

        try {
            $message = $this->despachar($conversation, $data, $parser);
        } catch (InteractiveMessageException $e) {
            // Solo alcanza a la vía explícita: el agente mandó un objeto
            // interactivo que no cumple los límites de Meta, y sabe que lo
            // mandó, así que un 422 es accionable. La vía del marcador nunca
            // llega acá — degrada a texto antes, ver despachar().
            return response()->json([
                'message' => $e->getMessage(),
                'campo'   => $e->campo,
                'valor'   => $e->valor,
                'limite'  => $e->limite,
            ], $e->status());
        }

        return response()->json([
            'message' => ['id' => $message->id, 'status' => $message->status, 'sender' => $message->sender],
        ]);
    }

    /**
     * Las dos formas que tiene el agente de mandar un menú, por orden de precedencia.
     *
     * 1. VÍA EXPLÍCITA — `interactive` en el body, formato Cloud API. El agente
     *    define los ids y enruta por ellos; si no cumple los límites recibe un
     *    422, porque sabe que mandó un menú y puede corregirlo.
     *
     * 2. VÍA DEL MARCADOR — texto con «1.-) Opción». El agente cree que manda
     *    texto plano y NO sabe que existe esta conversión, así que acá no hay
     *    errores: si no entra en los límites sale como texto numerado legible y
     *    el motivo queda en `payload.interactive_skipped`. Un 422 lo dejaría
     *    mudo frente al cliente, que es el fallo que esto viene a arreglar. Los
     *    ids los genera el CRM (`opt:N`) para que no pueda alucinar uno.
     *
     * 3. Texto normal, que es el caso de siempre.
     *
     * @param  array<string, mixed>  $data
     */
    protected function despachar(Conversation $conversation, array $data, InteractiveParser $parser): Message
    {
        if (filled($data['interactive'] ?? null)) {
            return $this->delivery->interactivo(
                $conversation,
                OutboundInteractive::fromArray($this->desdeFormatoCloudApi($data['interactive'])),
            );
        }

        $texto = (string) ($data['text'] ?? '');
        $parsed = $parser->parse($texto);

        if ($parsed?->isInteractive()) {
            return $this->delivery->interactivo($conversation, $parsed->interactive);
        }

        if ($parsed) {
            return $this->delivery->textoDegradado($conversation, $parsed->fallbackText, $parsed->reason);
        }

        return $this->delivery->texto($conversation, $texto, $data['reply_to_id'] ?? null);
    }

    /**
     * Traduce el objeto de la Cloud API a la forma que entiende el DTO.
     *
     * El equipo del agente manda el formato de Meta —anidado, con `action` y
     * `reply`— y el DTO trabaja con una lista plana de opciones. Se traduce acá,
     * en el borde, para que adentro haya una sola representación.
     *
     * @param  array<string, mixed>  $interactive
     * @return array<string, mixed>
     */
    protected function desdeFormatoCloudApi(array $interactive): array
    {
        $esBotones = ($interactive['type'] ?? null) === 'button';

        $opciones = $esBotones
            ? array_map(fn ($b) => [
                'id'    => $b['reply']['id'] ?? '',
                'title' => $b['reply']['title'] ?? '',
            ], $interactive['action']['buttons'] ?? [])
            : array_merge(...array_map(
                fn ($s) => array_map(fn ($r) => [
                    'id'          => $r['id'] ?? '',
                    'title'       => $r['title'] ?? '',
                    'description' => $r['description'] ?? null,
                ], $s['rows'] ?? []),
                $interactive['action']['sections'] ?? [[]],
            ));

        return [
            'kind'        => $esBotones ? OutboundInteractive::BUTTONS : OutboundInteractive::LIST,
            'body'        => $interactive['body']['text'] ?? '',
            'header'      => $interactive['header']['text'] ?? null,
            'footer'      => $interactive['footer']['text'] ?? null,
            'list_button' => $interactive['action']['button'] ?? null,
            'options'     => $opciones,
        ];
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
