<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ServiceZone;

/**
 * Catálogo de Tessa: 8 SERVICIOS (products) con sus datos (información/beneficios
 * y sesión/frecuencia, del cuadro de Tessa) y sus ZONAS (service_zones, con
 * duración + precio). Los precios/duraciones son MOCK hasta tener la lista real.
 *
 * Idempotente: updateOrCreate por sku (servicio) y por (product_id, name) (zona).
 */
class TessaMockCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            'DEPILACION-LASER' => [
                'name'        => 'Depilación láser',
                'description' => 'Servicio estrella de Tessa, en todo el cuerpo. Las zonas más solicitadas son axilas y bikini. Reducción progresiva del vello con sesiones sucesivas (cada zona dura 30 minutos).',
                'frequency'   => '6 a 8 sesiones, a veces hasta 10, una por mes.',
                'interval'    => 30,
                'sessions'    => [6, 10],
                'zones'       => [
                    ['Axilas', 30, 70], ['Bikini', 30, 120], ['Bozo / labio', 30, 50],
                    ['Rostro completo', 30, 150], ['Medias piernas', 30, 160], ['Piernas completas', 30, 280],
                    ['Brazos', 30, 150], ['Espalda', 30, 200], ['Axilas + Bikini', 60, 170],
                ],
            ],
            'HIFU' => [
                'name'        => 'HIFU',
                'description' => 'Rejuvenece la piel y regenera colágeno y elastina. Se aplica en rostro, o en rostro y cuello.',
                'frequency'   => 'Cada 90 días, como plazo mínimo. Total de sesiones según evaluación y avance de cada paciente.',
                'interval'    => 90,
                'sessions'    => [null, null],
                'zones'       => [['Rostro', 60, 900], ['Rostro y cuello', 90, 1300]],
            ],
            'DERMAPEN' => [
                'name'        => 'Dermapen',
                'description' => 'Cumple la misma función que el HIFU: rejuvenecimiento de la piel y regeneración de colágeno y elastina.',
                'frequency'   => 'Cada 30 días. Total de sesiones según evaluación y avance de cada paciente.',
                'interval'    => 30,
                'sessions'    => [null, null],
                'zones'       => [['Facial', 30, 350]],
            ],
            'CRIOLIPOLISIS' => [
                'name'        => 'Criolipólisis',
                'description' => 'Reduce la grasa localizada mediante frío controlado.',
                'frequency'   => 'Cada 30 días. Total de sesiones según evaluación y avance de cada paciente.',
                'interval'    => 30,
                'sessions'    => [null, null],
                'zones'       => [['Por zona', 60, 120], ['Paquete 15 zonas', 60, 900]],
            ],
            'ULTRACAVITACION' => [
                'name'        => 'Ultracavitación',
                'description' => 'Reduce la grasa localizada mediante ultrasonido.',
                'frequency'   => 'Una vez por semana. Total de sesiones según evaluación y avance de cada paciente.',
                'interval'    => 7,
                'sessions'    => [null, null],
                'zones'       => [['Piernas / abdomen / espalda baja', 60, 150]],
            ],
            'VACUUM-RF' => [
                'name'        => 'Vacuum con radiofrecuencia',
                'description' => 'Apoya la reducción de celulitis y grasa, y mejora la firmeza de la piel.',
                'frequency'   => 'Una vez por semana. Total de sesiones según evaluación y avance de cada paciente.',
                'interval'    => 7,
                'sessions'    => [null, null],
                'zones'       => [['Piernas / abdomen / espalda baja', 60, 180]],
            ],
            'MADEROTERAPIA' => [
                'name'        => 'Maderoterapia',
                'description' => 'Modelado corporal; apoya la reducción de celulitis y grasa.',
                'frequency'   => 'Una vez por semana. Total de sesiones según evaluación y avance de cada paciente.',
                'interval'    => 7,
                'sessions'    => [null, null],
                'zones'       => [['Piernas / abdomen / espalda baja', 60, 120]],
            ],
            'MASOTERAPIA' => [
                'name'        => 'Masoterapia',
                'description' => 'Masaje terapéutico que alivia la tensión muscular y activa la circulación.',
                'frequency'   => 'Una vez por semana. Total de sesiones según evaluación y avance de cada paciente.',
                'interval'    => 7,
                'sessions'    => [null, null],
                'zones'       => [['Piernas / abdomen / espalda baja', 60, 150]],
            ],
        ];

        foreach ($services as $sku => $s) {
            $product = Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'name'                     => $s['name'],
                    'description'              => $s['description'],
                    'frequency_text'           => $s['frequency'],
                    'min_interval_days'        => $s['interval'],
                    'recommended_sessions_min' => $s['sessions'][0],
                    'recommended_sessions_max' => $s['sessions'][1],
                    'price'                    => null,   // el precio vive en la zona
                    'duration_minutes'         => null,   // la duración vive en la zona
                    'quantity'                 => 999,
                ]
            );

            foreach ($s['zones'] as [$zoneName, $duration, $price]) {
                ServiceZone::updateOrCreate(
                    ['product_id' => $product->id, 'name' => $zoneName],
                    ['duration_minutes' => $duration, 'price' => $price, 'is_active' => true]
                );
            }
        }

        $this->command?->info('Tessa: 8 servicios (con info/frecuencia) + zonas cargados.');
    }
}
