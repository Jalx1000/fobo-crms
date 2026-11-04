<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ServiceZone;

/**
 * Catálogo de Tessa: SERVICIOS (products) con sus datos (información/beneficios
 * y sesión/frecuencia) y sus ZONAS (service_zones, con duración + precio).
 *
 * HIFU, HIFU Corporal, Depilación láser y Dermapen llevan datos/precios REALES
 * (lista de Tessa, nov. 2026). El resto sigue con precios/duraciones MOCK hasta
 * tener la lista real. Las duraciones de HIFU son estimadas.
 *
 * Idempotente: updateOrCreate por sku (servicio) y por (product_id, name) (zona),
 * y borra de cada servicio las zonas que ya no estén en la lista (reemplazo).
 */
class TessaMockCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            'DEPILACION-LASER' => [
                'name'        => 'Depilación láser',
                'description' => 'Servicio estrella de Tessa con tecnología ALMA Soprano Titanium: 4 láseres en simultáneo (Alexandrita, Diodo y Nd:YAG en 2 profundidades) para una acción completa en todas las profundidades del folículo. Prácticamente indoloro, ayuda a atenuar manchas, unifica el tono de la piel y estimula el colágeno. Resultados visibles desde la primera sesión. Zonas más solicitadas: axilas y bikini.',
                'frequency'   => 'La mayoría de zonas: 6 a 8 sesiones; rostro y zona íntima: 8 a 10. Las primeras 5 sesiones una vez al mes; la 6ª y 7ª cada 40 días; las últimas cada 50 a 60 días. La clave está en la constancia.',
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
                'description' => 'Tecnología HIFU 25D PRO MAX 360°: combina ultrasonido focalizado, radiofrecuencia y estimulación muscular para mejorar la firmeza, estimular el colágeno y la elastina y definir el contorno. Tratamiento facial integral del rostro completo, papada y cuello (líneas de expresión, lifting, pómulos, surcos, óvalo facial y definición de mandíbula).',
                'frequency'   => 'Según la edad, la calidad de la piel y el grado de flacidez: puede realizarse cada 3, 4, 6 meses o una vez al año, para estimular el colágeno y mantener resultados progresivos y naturales.',
                'interval'    => 90,
                'sessions'    => [null, null],
                'zones'       => [
                    ['Óvalo facial + mandíbula + papada', 60, 900],
                    ['Rostro completo', 60, 1500],
                    ['Rostro completo + papada', 75, 1800],
                    ['Rostro completo + papada + cuello', 90, 2190],
                    ['Papada + cuello', 45, 800],
                ],
            ],
            'HIFU-CORPORAL' => [
                'name'        => 'HIFU Corporal',
                'description' => 'HIFU 25D PRO MAX 360° aplicado al cuerpo: mejora la firmeza, la definición y el contorno (brazos, cintura, abdomen, glúteos y piernas) combinando ultrasonido focalizado, radiofrecuencia y estimulación muscular.',
                'frequency'   => 'Protocolo de 3 sesiones, cada una con un intervalo de 30 a 40 días.',
                'interval'    => 30,
                'sessions'    => [3, 3],
                // Precio por zona = paquete completo de 3 sesiones (Bs 1.090).
                'zones'       => [
                    ['Papada y región submentoniana', 60, 1090],
                    ['Posterior de brazos + zona subaxilar', 60, 1090],
                    ['Abdomen bajo', 60, 1090],
                    ['Rollitos de la cintura', 60, 1090],
                ],
            ],
            'DERMAPEN' => [
                'name'        => 'Dermapen',
                'description' => 'Renovación y rejuvenecimiento facial: estimula la regeneración natural de la piel, mejora la textura, suaviza líneas de expresión, minimiza la apariencia de los poros y aporta luminosidad, firmeza y un aspecto más uniforme. Potenciado con activos de última generación: Exosomas + PDRN (regeneración, hidratación y elasticidad) y Péptidos + GH-Cu / péptido de cobre (reparación cutánea y firmeza).',
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

            // Reemplazo: elimina zonas del servicio que ya no están en la lista
            // (p. ej. las zonas mock anteriores de HIFU). Las promos que apunten a
            // una zona borrada quedan con service_zone_id nulo (nullOnDelete).
            $keepNames = array_map(fn ($z) => $z[0], $s['zones']);
            ServiceZone::where('product_id', $product->id)
                ->whereNotIn('name', $keepNames)
                ->delete();
        }

        $this->command?->info('Tessa: '.count($services).' servicios (con info/frecuencia) + zonas cargados.');
    }
}
