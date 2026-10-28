<?php

namespace Webkul\Admin\Http\Controllers\Products;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Promociones de un servicio (promotions): precio promocional o % de descuento,
 * con vigencia y, opcionalmente, para una zona específica. CRUD vía AJAX desde
 * la pestaña "Promociones" del servicio.
 */
class PromotionController extends Controller
{
    public function __construct(protected ProductRepository $productRepository) {}

    /**
     * Promociones del servicio + sus zonas (para el selector).
     */
    public function index(int $id): JsonResponse
    {
        $product = $this->productRepository->findOrFail($id);

        return response()->json([
            'data'  => $product->promotions()
                ->orderByDesc('valid_from')
                ->get(['id', 'service_zone_id', 'name', 'price', 'discount_percent', 'valid_from', 'valid_to', 'is_active']),
            'zones' => $product->zones()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Sincroniza las promociones del servicio (upsert + borrado de las removidas).
     */
    public function sync(Request $request, int $id): JsonResponse
    {
        $product = $this->productRepository->findOrFail($id);

        $zoneIds = $product->zones()->pluck('id')->all();

        $data = $request->validate([
            'promotions'                    => ['present', 'array'],
            'promotions.*.id'               => ['nullable', 'integer'],
            'promotions.*.service_zone_id'  => ['nullable', 'integer', 'in:'.implode(',', $zoneIds ?: [0])],
            'promotions.*.name'             => ['nullable', 'string', 'max:255'],
            'promotions.*.price'            => ['nullable', 'numeric', 'min:0'],
            'promotions.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'promotions.*.valid_from'       => ['nullable', 'date'],
            'promotions.*.valid_to'         => ['nullable', 'date'],
            'promotions.*.is_active'        => ['boolean'],
        ]);

        $keepIds = [];

        foreach ($data['promotions'] as $promo) {
            $attributes = [
                'service_zone_id'  => $promo['service_zone_id'] ?? null,
                'name'             => $promo['name'] ?? null,
                'price'            => $promo['price'] ?? null,
                'discount_percent' => $promo['discount_percent'] ?? null,
                'valid_from'       => $promo['valid_from'] ?? null,
                'valid_to'         => $promo['valid_to'] ?? null,
                'is_active'        => $promo['is_active'] ?? true,
            ];

            $model = ! empty($promo['id'])
                ? $product->promotions()->find($promo['id'])
                : null;

            if ($model) {
                $model->update($attributes);
            } else {
                $model = $product->promotions()->create($attributes);
            }

            $keepIds[] = $model->id;
        }

        $product->promotions()->whereNotIn('id', $keepIds ?: [0])->delete();

        return response()->json(['message' => 'Promociones actualizadas.']);
    }
}
