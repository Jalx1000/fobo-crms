<?php

namespace Webkul\Whatsapp\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\PatientAppointments;
use Webkul\Whatsapp\Gateways\Contracts\SupportsFlows;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\Flows\FlowToken;

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
     * Va atado a la CONVERSACIÓN -o sea, al paciente- y no a un lead: los leads
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
     *
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

    /**
     * HERRAMIENTA DEL AGENTE: registra la respuesta del paciente al recordatorio.
     *
     * POST /api/v1/whatsapp/conversations/{id}/appointments/respond
     * {respuesta: "confirma"|"no_asiste", activity_id?, lead_id?}
     *
     * Sin activity_id ni lead_id vale solo si hay UN recordatorio sin contestar
     * (patient_context.recordatorios); con varios ("confirmo ambas") el agente
     * llama una vez por cita. La cita tiene que ser del contacto, de sus
     * pacientes a cargo o una que se le recordó a esta conversación.
     */
    public function respondAppointment(Request $request, string $id, PatientAppointments $appointments): JsonResponse
    {
        $data = $request->validate([
            'respuesta'   => 'required|string|in:confirma,no_asiste',
            'activity_id' => 'nullable|integer',
            'lead_id'     => 'nullable|integer',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);
        $reminders = $appointments->remindersOf((int) $conversation->id);

        $activityId = (int) ($data['activity_id'] ?? 0);

        if (! $activityId && ! empty($data['lead_id'])) {
            $activityId = (int) DB::table('lead_activities')
                ->join('activities', 'activities.id', '=', 'lead_activities.activity_id')
                ->where('lead_activities.lead_id', $data['lead_id'])
                ->where('activities.type', 'meeting')
                ->orderByDesc('activities.schedule_from')
                ->value('activities.id');
        }

        if (! $activityId && empty($data['lead_id'])) {
            $pending = array_values(array_filter($reminders, fn ($r) => $r['respuesta'] === null));

            if (count($pending) !== 1) {
                return response()->json([
                    'message'       => $pending
                        ? 'Hay varios recordatorios sin contestar: indicá activity_id (una llamada por cita).'
                        : 'No hay recordatorios sin contestar: indicá activity_id o lead_id de la cita.',
                    'recordatorios' => $pending,
                ], 422);
            }

            $activityId = $pending[0]['activity_id'];
        }

        $reminded = collect($reminders)->firstWhere('activity_id', $activityId);
        $own = $conversation->person_id ? $appointments->findFor((int) $conversation->person_id, $activityId) : null;

        if (! $activityId || (! $reminded && ! $own)) {
            return response()->json([
                'message' => 'La cita no existe, ya pasó o no es de este contacto.',
            ], 422);
        }

        $smd = $appointments->respond($activityId, $data['respuesta'], auth()->id());

        return response()->json([
            'respuesta' => $data['respuesta'],
            'cita'      => $reminded ?? [
                'activity_id' => $own['activity_id'],
                'lead_id'     => $own['lead_id'],
                'paciente'    => $own['patient_name'],
                'fecha'       => \Carbon\Carbon::parse($own['schedule_from'])->format('d/m/Y'),
                'hora'        => \Carbon\Carbon::parse($own['schedule_from'])->format('H:i'),
                'doctor'      => $own['doctor_name'],
            ],
            'smd'       => $smd,
        ]);
    }

    /**
     * HERRAMIENTA DEL AGENTE: envía un formulario de WhatsApp (Flow).
     *
     * POST /api/v1/whatsapp/conversations/{id}/flows {flow, text?}
     *
     * flow: "paciente_tercero" (agendar), "cancelar_cita" o "reprogramar_cita".
     * Cuando el paciente lo completa, al agente le llega un mensaje
     * `flow_reply` con los datos en `message.flow` (ver docs/agente-ia-integracion.md).
     */
    public function sendFlow(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'flow' => 'required|string|in:'.implode(',', \Webkul\Whatsapp\Services\Flows\FlowDefinitions::keys()),
            'text' => 'nullable|string|max:1000',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $key = config('whatsapp.outbound_gateway');
        $gateway = $key ? $this->gateways->driver((string) $key) : $this->gateways->for($conversation);

        if (! $gateway instanceof SupportsFlows) {
            return response()->json(['message' => 'Este canal no soporta formularios de WhatsApp (Flows).'], 422);
        }

        $window = MessagingWindow::compute($gateway->messagingWindowHours(), $conversation->last_inbound_at);

        if ($window->applies && ! $window->open) {
            return response()->json([
                'message' => 'Fuera de la ventana de 24h de WhatsApp: el formulario solo se puede enviar mientras la conversación está abierta.',
            ], 422);
        }

        $config = \Webkul\Whatsapp\Services\Flows\FlowDefinitions::config($data['flow']);
        $token = FlowToken::issue((int) $conversation->id, $data['flow']);

        // Pre-llenado (solo agendar): lo que el paciente ya dijo en el chat. No se
        // valida como obligatorio a propósito: un valor raro se descarta, nunca
        // impide mandar el formulario.
        $prefill = $data['flow'] === 'paciente_tercero'
            ? \Webkul\Whatsapp\Services\Flows\FlowPrefill::sanitize($request->input('prefill'))
            : [];

        $message = MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'flow',
            'body'            => $data['text'] ?? \Webkul\Whatsapp\Services\Flows\FlowDefinitions::defaultText($data['flow']),
            'status'          => 'queued',
            'sender'          => 'ia',
            'payload'         => ['flow' => [
                'key'   => $data['flow'],
                'token' => $token,
                'name'  => $config['name'] ?? null,
                'id'    => $config['id'] ?? null,
                'cta'   => $config['cta'] ?? 'Completar datos',
                'mode'  => $config['mode'] ?? 'published',
            ] + ($prefill ? ['prefill' => $prefill] : [])],
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));

        return response()->json([
            'message'    => ['id' => $message->id, 'status' => $message->status, 'type' => 'flow'],
            'flow_token' => $token,
            // Lo que se tomó del prefill, ya normalizado (vacío si no aplica).
            'prefill'    => (object) $prefill,
        ]);
    }
}
