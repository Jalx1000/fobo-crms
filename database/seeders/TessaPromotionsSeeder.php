<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Contact\Models\Branch;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\Promotion;
use Webkul\Product\Models\ServiceZone;

/**
 * Promociones vigentes de Tessa. Los precios están en Bs (Bolivianos), así que
 * se acotan a la sucursal Santa Cruz; si la sucursal no existe aún, quedan
 * globales (branch_id nulo).
 *
 * Noviembre 2026 (01 al 30):
 *  - Depilación láser · Axilas + Bikini → Bs 150 (precio base de zona Bs 170).
 *  - HIFU · Rostro completo → Bs 1.500 (promo "de este mes").
 *
 * Requiere que los servicios y zonas ya existan (ver TessaMockCatalogSeeder).
 * Idempotente: updateOrCreate por (product_id, service_zone_id, valid_from).
 */
class TessaPromotionsSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = Branch::where('slug', 'santa-cruz')->value('id');

        if (! $branchId) {
            $this->command?->warn('Sucursal "santa-cruz" no encontrada: las promos quedarán globales.');
        }

        $validFrom = '2026-11-01';
        $validTo   = '2026-11-30';

        $promos = [
            [
                'sku'   => 'DEPILACION-LASER',
                'zone'  => 'Axilas + Bikini',
                'name'  => 'Axilas + Línea de Bikini (noviembre)',
                'price' => 150,
            ],
            [
                'sku'   => 'HIFU',
                'zone'  => 'Rostro completo',
                'name'  => 'HIFU 25D Rostro completo (noviembre)',
                'price' => 1500,
            ],
        ];

        foreach ($promos as $p) {
            $product = Product::where('sku', $p['sku'])->first();

            if (! $product) {
                $this->command?->warn("Promo omitida: no existe el servicio {$p['sku']}.");

                continue;
            }

            $zoneId = ServiceZone::where('product_id', $product->id)
                ->where('name', $p['zone'])
                ->value('id');

            if (! $zoneId) {
                $this->command?->warn("Promo omitida: no existe la zona '{$p['zone']}' en {$p['sku']}.");

                continue;
            }

            Promotion::updateOrCreate(
                [
                    'product_id'      => $product->id,
                    'service_zone_id' => $zoneId,
                    'valid_from'      => $validFrom,
                ],
                [
                    'branch_id'        => $branchId,
                    'name'             => $p['name'],
                    'price'            => $p['price'],
                    'discount_percent' => null,
                    'valid_to'         => $validTo,
                    'is_active'        => true,
                ]
            );
        }

        $this->command?->info('Tessa: promociones de noviembre (Santa Cruz) cargadas.');
    }
}
