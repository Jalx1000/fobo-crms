<?php

namespace Webkul\Inmobiliaria\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;
use Webkul\Lead\Models\LeadProxy;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Jobs\NotifyHandoff;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Repositories\HandoffRepository;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * Derivación de la conversación a un asesor.
 *
 * SE DERIVA AL DUEÑO DEL LEAD, NO AL TITULAR DEL INMUEBLE (spec
 * tablero-por-asesor, D13). Un asesor solo ve las conversaciones de sus leads
 * (D10) y solo el asignado puede tomar la derivación (D14): derivarle al
 * titular un lead ajeno le dejaba una derivación que no podía ver y que nadie
 * más podía tomar. El código del inmueble queda como referencia, para que el
 * asesor sepa por qué casa preguntó el cliente.
 */
class HandoffController extends Controller
{
    public function __construct(
        protected InmuebleRepository $inmuebles,
        protected HandoffRepository $handoffs,
        protected AgentPayload $payloads,
    ) {}

    public function derivar(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => 'nullable|string',
            'reason' => 'nullable|string|max:500',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $inmueble = filled($datos['codigo'] ?? null)
            ? $this->inmuebles->porCodigo($datos['codigo'], soloOfrecibles: false)
            : null;

        if (filled($datos['codigo'] ?? null) && ! $inmueble) {
            return response()->json(['message' => "No existe el inmueble {$datos['codigo']}."], 404);
        }

        $titular = $this->duenoDelLead($conversation)
            ?? $this->activo(config('inmobiliaria.inmuebles.asesor_por_defecto'));

        if (! $titular) {
            return response()->json([
                'message' => 'No hay a quién derivar: la conversación no tiene lead con dueño activo y no hay asesor por defecto.',
            ], 409);
        }

        // Idempotente: si ya había uno abierto devuelve ese, sin cambiar el
        // titular. El agente ya le dijo al cliente quién le va a escribir.
        $handoff = $this->handoffs->abrir($conversation->id, $titular, [
            'referencia_id'     => $inmueble?->id,
            'referencia_codigo' => $inmueble?->codigo,
            'reason'            => $datos['reason'] ?? null,
        ]);

        // El agente deja de recibir eventos de esta conversación desde acá.
        $conversation->forceFill(['ai_enabled' => false])->save();

        // Solo cuando la derivación es nueva: el agente pide handoff en casi
        // cada mensaje, y un mail por mensaje entrenaría al asesor a ignorarlos.
        if ($handoff->wasRecentlyCreated) {
            NotifyHandoff::dispatch($handoff->id)->onQueue(config('whatsapp.queue'));
        }

        return response()->json([
            'message' => 'Conversación derivada.',
            'handoff' => [
                'id'     => $handoff->id,
                'asesor' => $handoff->asesor?->name,
                'estado' => $handoff->state,
                'codigo' => $handoff->referencia_codigo,
                'nuevo'  => $handoff->wasRecentlyCreated,
            ],
        ]);
    }

    /**
     * El id solo si ese usuario todavía puede atender.
     *
     * Sin este filtro, una conversación derivada al titular de un inmueble que
     * ya no trabaja acá queda asignada a una cuenta muerta: nadie la ve, nadie
     * la toma, y al cliente no se le avisó nada porque al derivar no se le
     * manda nada. Se pudre en silencio.
     */
    protected function activo(?int $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        return UserProxy::modelClass()::where('id', $userId)->where('status', 1)->exists()
            ? $userId
            : null;
    }

    /**
     * El dueño del lead de la conversación, si sigue activo.
     *
     * Primero el lead vinculado a la conversación; si no tiene, el lead más
     * reciente del contacto — el mismo que el inbox muestra como «su» lead.
     * Si no hay ninguno (el cliente eligió «Hablar con un asesor» en el primer
     * menú, antes de que exista un lead), el llamador cae al asesor por defecto.
     */
    protected function duenoDelLead($conversation): ?int
    {
        if ($dueno = $this->activo($conversation->lead?->user_id)) {
            return $dueno;
        }

        if (! $conversation->person_id) {
            return null;
        }

        $lead = LeadProxy::modelClass()::where('person_id', $conversation->person_id)
            ->orderByRaw('status = 1 desc')
            ->latest('id')
            ->first();

        return $this->activo($lead?->user_id);
    }
}
