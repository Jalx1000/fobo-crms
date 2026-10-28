<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ServiceZone;

/**
 * Catálogo MOCK de Tessa: 8 SERVICIOS (products), cada uno con sus ZONAS
 * (service_zones). La duración y el precio viven en la zona (depilación axilas
 * 30 min / Bs 70, piernas 30 min / Bs 280, HIFU rostro+cuello 90 min...).
 *
 * Precios inventados para prototipar; se reemplazan con la lista real de Tessa.
 * Idempotente: updateOrCreate por sku (servicio) y por (product_id, name) (zona).
 */
class TessaMockCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // sku => [nombre del servicio, [ [zona, duración, precio], ... ]]
        $services = [
            'DEPILACION-LASER' => ['Depilación láser', [
                ['Axilas', 30, 70],
                ['Bikini', 30, 120],
                ['Bozo / labio', 30, 50],
                ['Rostro completo', 30, 150],
                ['Medias piernas', 30, 160],
                ['Piernas completas', 30, 280],
                ['Brazos', 30, 150],
                ['Espalda', 30, 200],
                ['Axilas + Bikini', 60, 170],
            ]],
            'HIFU' => ['HIFU', [
                ['Rostro', 60, 900],
                ['Rostro y cuello', 90, 1300],
            ]],
            'DERMAPEN' => ['Dermapen', [
                ['Facial', 30, 350],
            ]],
            'CRIOLIPOLISIS' => ['Criolipólisis', [
                ['Por zona', 60, 120],
                ['Paquete 15 zonas', 60, 900],
            ]],
            'ULTRACAVITACION' => ['Ultracavitación', [
                ['Piernas / abdomen / espalda baja', 60, 150],
            ]],
            'VACUUM-RF' => ['Vacuum con radiofrecuencia', [
                ['Piernas / abdomen / espalda baja', 60, 180],
            ]],
            'MADEROTERAPIA' => ['Maderoterapia', [
                ['Piernas / abdomen / espalda baja', 60, 120],
            ]],
            'MASOTERAPIA' => ['Masoterapia', [
                ['Piernas / abdomen / espalda baja', 60, 150],
            ]],
        ];

        foreach ($services as $sku => [$name, $zones]) {
            // El servicio ya no lleva duración/precio: viven en la zona.
            $product = Product::updateOrCreate(
                ['sku' => $sku],
                ['name' => $name, 'price' => null, 'duration_minutes' => null, 'quantity' => 999]
            );

            foreach ($zones as [$zoneName, $duration, $price]) {
                ServiceZone::updateOrCreate(
                    ['product_id' => $product->id, 'name' => $zoneName],
                    ['duration_minutes' => $duration, 'price' => $price, 'is_active' => true]
                );
            }
        }

        $this->command?->info('Tessa: 8 servicios + zonas (precios mock) cargados.');
    }
}
