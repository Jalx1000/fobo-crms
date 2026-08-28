<?php

namespace Webkul\Inmobiliaria\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Jobs\NotifyHandoff;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Repositories\HandoffRepository;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * Derivación al asesor titular.
 *
 * Vive en este módulo y no en WhatsApp porque para saber A QUIÉN derivar hay que
 * resolver el titular a partir del código del inmueble, y eso es conocimiento
 * del catálogo. El módulo de WhatsApp aporta la mecánica; el inmobiliario, la
 * regla de negocio.
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

        $titular = $this->activo($inmueble?->user_id) ?? $this->fallback($conversation);

        if (! $titular) {
            return response()->json([
                'message' => 'No hay a quién derivar: el inmueble no tiene asesor titular y la conversación no tiene dueño.',
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
     * A quién derivar cuando no vino un código de inmueble.
     *
     * Se usa el dueño del lead de la conversación. Si tampoco hay, se responde
     * 409 en vez de elegir a cualquiera: derivar al azar es peor que no derivar,
     * porque el cliente queda esperando a alguien que no sabe que lo esperan.
     */
    protected function fallback($conversation): ?int
    {
        // El dueño del lead, si la conversación ya generó uno.
        if ($titular = $this->activo($conversation->lead?->user_id)) {
            return $titular;
        }

        /*
        | "Hablar con un asesor" es una opción del PRIMER menú del agente: ahí
        | no hay inmueble ni lead todavía. Sin un asesor de guardia, esa opción
        | devolvería 409 y el agente no podría cumplir lo que acaba de ofrecer.
        */
        return $this->activo(config('inmobiliaria.inmuebles.asesor_por_defecto'));
    }
}
