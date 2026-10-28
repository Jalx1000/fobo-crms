<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ServiceCategory;

/**
 * Categorías transversales (Facial / Corporal / Depilación) y su asignación a
 * los servicios. Un servicio puede estar en varias (Depilación láser =
 * Depilación + Facial). Idempotente: updateOrCreate por slug + sync.
 */
class TessaServiceCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'facial'     => 'Facial',
            'corporal'   => 'Corporal',
            'depilacion' => 'Depilación',
        ];

        $cat = [];
        foreach ($categories as $slug => $name) {
            $cat[$slug] = ServiceCategory::updateOrCreate(['slug' => $slug], ['name' => $name])->id;
        }

        // sku del servicio => slugs de categoría
        $map = [
            'DEPILACION-LASER' => ['depilacion', 'facial'],
            'HIFU'             => ['facial'],
            'DERMAPEN'         => ['facial'],
            'CRIOLIPOLISIS'    => ['corporal'],
            'ULTRACAVITACION'  => ['corporal'],
            'VACUUM-RF'        => ['corporal'],
            'MADEROTERAPIA'    => ['corporal'],
            'MASOTERAPIA'      => ['corporal'],
        ];

        foreach ($map as $sku => $slugs) {
            $product = Product::where('sku', $sku)->first();
            if (! $product) {
                continue;
            }
            $product->categories()->sync(array_map(fn ($s) => $cat[$s], $slugs));
        }

        $this->command?->info('Tessa: categorías transversales (Facial/Corporal/Depilación) asignadas.');
    }
}
