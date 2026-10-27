<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;

/**
 * Catálogo MOCK de servicios de Tessa (spa estético).
 *
 * Valores de precio inventados para prototipar el flujo conversacional;
 * se reemplazan cuando Tessa entregue su lista real de precios/promos.
 *
 * Cada fila es un `product` seleccionable (servicio + zona/variante). El agente
 * lista las opciones, la paciente elige una, y de ahí salen los minutos a
 * bloquear en la agenda (duration_minutes) y el monto a cobrar (price).
 *
 * Idempotente: updateOrCreate por SKU. Seguro de re-correr en dev.
 */
class TessaMockCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // [sku, nombre, zona/variante, duración (min), precio Bs, descripción]
        $catalog = [
            // ---- Depilación láser (30 min por zona; "varias zonas" = suma) ----
            ['DEPILA-AXILAS',        'Depilación láser — Axilas',             30,   70, 'Reducción progresiva del vello. Zona más solicitada.'],
            ['DEPILA-BIKINI',        'Depilación láser — Bikini',             30,  120, 'Reducción progresiva del vello. Zona más solicitada.'],
            ['DEPILA-BOZO',          'Depilación láser — Bozo / labio',       30,   50, 'Depilación de zona facial.'],
            ['DEPILA-ROSTRO',        'Depilación láser — Rostro completo',    30,  150, 'Depilación de zona facial.'],
            ['DEPILA-MEDIAS-PIERNAS','Depilación láser — Medias piernas',     30,  160, 'Reducción progresiva del vello.'],
            ['DEPILA-PIERNAS',       'Depilación láser — Piernas completas',  30,  280, 'Reducción progresiva del vello.'],
            ['DEPILA-BRAZOS',        'Depilación láser — Brazos',             30,  150, 'Reducción progresiva del vello.'],
            ['DEPILA-ESPALDA',       'Depilación láser — Espalda',            30,  200, 'Reducción progresiva del vello.'],
            ['DEPILA-AXILAS-BIKINI', 'Depilación láser — Axilas + Bikini',    60,  170, 'Combo de dos zonas en la misma sesión (30 min por zona).'],

            // ---- Faciales de rejuvenecimiento ----
            ['HIFU-ROSTRO',        'HIFU — Rostro',            60,  900, 'Rejuvenece la piel y regenera colágeno y elastina. Cada 90 días mínimo.'],
            ['HIFU-ROSTRO-CUELLO', 'HIFU — Rostro y cuello',   90, 1300, 'Rejuvenece la piel y regenera colágeno y elastina. Cada 90 días mínimo.'],
            ['DERMAPEN-FACIAL',    'Dermapen — Facial',        30,  350, 'Rejuvenecimiento facial; regeneración de colágeno y elastina. Cada 30 días.'],

            // ---- Reducción de grasa localizada (corporales, 60 min) ----
            ['CRIO-ZONA',       'Criolipólisis — Por zona',                    60,  120, 'Reduce grasa localizada mediante frío controlado. Cada 30 días.'],
            ['CRIO-PAQUETE-15', 'Criolipólisis — Paquete 15 zonas',            60,  900, 'Paquete de 15 zonas (pago completo al contratar).'],
            ['ULTRACAV',        'Ultracavitación',                             60,  150, 'Reduce grasa localizada mediante ultrasonido. Una vez por semana.'],
            ['VACUUM-RF',       'Vacuum con radiofrecuencia',                  60,  180, 'Apoya la reducción de celulitis y grasa; mejora firmeza. Una vez por semana.'],
            ['MADERO',          'Maderoterapia',                               60,  120, 'Modelado corporal; apoya reducción de celulitis y grasa. Una vez por semana.'],
            ['MASO',            'Masoterapia',                                 60,  150, 'Masaje terapéutico; alivia tensión muscular y activa la circulación. Una vez por semana.'],
        ];

        foreach ($catalog as [$sku, $name, $duration, $price, $description]) {
            Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'name'             => $name,
                    'description'      => $description,
                    'price'            => $price,
                    'duration_minutes' => $duration,
                    'quantity'         => 999, // servicios: sin control de stock
                ]
            );
        }

        $this->command?->info('Tessa: '.count($catalog).' servicios mock cargados (precios placeholder en Bs).');
    }
}
