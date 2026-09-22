<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Core\Services\PhoneNumber;
use Webkul\Lead\Models\Lead;

/**
 * "Los pedidos de este teléfono" — the question the WhatsApp agent actually
 * asks, answered in one call.
 *
 * The alternative was two calls (persons/search then leads?person_id=), and the
 * search half matches contact_numbers with a raw LIKE: looking up
 * "59176616013" would miss the same customer stored as "76616013". Here the
 * number goes through the shared normalization, so every way the CRM holds a
 * line resolves to the same person — and to their complete order history.
 */
class LeadLookupController extends Controller
{
    public function __construct(protected PersonRepository $persons) {}

    public function byPhone(Request $request): JsonResponse
    {
        $phone = (string) $request->query('telefono', $request->query('phone', ''));

        if (strlen(PhoneNumber::digits($phone)) < 7) {
            return response()->json([
                'message' => 'El parámetro telefono es requerido y debe tener al menos 7 dígitos.',
            ], 400);
        }

        $person = $this->persons->findByPhone($phone);

        if (! $person) {
            // Not an error: a number nobody has ordered from yet is a normal
            // answer, and an empty list is easier for the agent than a 404.
            return response()->json([
                'telefono' => PhoneNumber::normalize($phone),
                'persona'  => null,
                'pedidos'  => [],
                'total'    => 0,
            ]);
        }

        $leads = Lead::with(['stage', 'pipeline', 'user', 'products.product'])
            ->where('person_id', $person->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Lead $lead) => [
                'id'          => $lead->id,
                'titulo'      => $lead->title,
                'descripcion' => $lead->description,
                'monto'       => (float) $lead->lead_value,
                'estado'      => $lead->status,
                'etapa'       => optional($lead->stage)->name,
                'pipeline'    => optional($lead->pipeline)->name,
                'asesor'      => optional($lead->user)->name,
                'creado_en'   => optional($lead->created_at)->toIso8601String(),
                'cerrado_en'  => optional($lead->closed_at)->toIso8601String(),
                'productos'   => $lead->products->map(fn ($item) => [
                    'producto_id' => $item->product_id,
                    'nombre'      => optional($item->product)->name,
                    'cantidad'    => (int) $item->quantity,
                    'precio'      => (float) $item->price,
                    'subtotal'    => (float) $item->amount,
                ])->values(),
            ]);

        return response()->json([
            'telefono' => PhoneNumber::normalize($phone),
            'persona'  => [
                'id'              => $person->id,
                'nombre'          => $person->name,
                'contact_numbers' => $person->contact_numbers,
            ],
            'pedidos' => $leads,
            'total'   => $leads->count(),
        ]);
    }
}
