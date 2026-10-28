<?php

namespace Webkul\Admin\Http\Controllers\Products;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Zonas de un servicio (service_zones): la variante elegible que lleva la
 * duración y el precio. CRUD vía AJAX desde la pestaña "Zonas" del servicio.
 */
class ServiceZoneController extends Controller
{
    public function __construct(protected ProductRepository $productRepository) {}

    /**
     * Zonas del servicio.
     */
    public function index(int $id): JsonResponse
    {
        $product = $this->productRepository->findOrFail($id);

        return response()->json([
            'data' => $product->zones()
                ->orderBy('name')
                ->get(['id', 'name', 'duration_minutes', 'price']),
        ]);
    }

    /**
     * Sincroniza las zonas del servicio (upsert + borrado de las removidas).
     */
    public function sync(Request $request, int $id): JsonResponse
    {
        $product = $this->productRepository->findOrFail($id);

        $data = $request->validate([
            'zones'                    => ['present', 'array'],
            'zones.*.id'               => ['nullable', 'integer'],
            'zones.*.name'             => ['required', 'string', 'max:255'],
            'zones.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'zones.*.price'            => ['nullable', 'numeric', 'min:0'],
        ]);

        $keepIds = [];

        foreach ($data['zones'] as $zone) {
            $attributes = [
                'name'             => $zone['name'],
                'duration_minutes' => $zone['duration_minutes'] ?? null,
                'price'            => $zone['price'] ?? null,
                'is_active'        => true,
            ];

            $model = ! empty($zone['id'])
                ? $product->zones()->find($zone['id'])
                : null;

            if ($model) {
                $model->update($attributes);
            } else {
                $model = $product->zones()->create($attributes);
            }

            $keepIds[] = $model->id;
        }

        // Borrar las zonas que se quitaron en la UI.
        $product->zones()->whereNotIn('id', $keepIds ?: [0])->delete();

        return response()->json(['message' => 'Zonas actualizadas.']);
    }
}
