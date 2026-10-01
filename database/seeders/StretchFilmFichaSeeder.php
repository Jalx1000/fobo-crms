<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;
use Webkul\Product\Support\FichaTecnica;

/**
 * Medidas y ficha técnica de las dos variantes de Stretch Film (16/09/2026).
 *
 * Las pasó el negocio tal como vienen del proveedor. Se cargan acá y no a mano
 * porque son dos fichas de once líneas cada una y hay que poder repetirlas si
 * se recrea el catálogo.
 *
 * `medida` es la medida física del rollo, corta: es lo que se imprime en la
 * línea de la cotización, al lado del código. El resto va a la ficha técnica de
 * la variante, que es donde manual y automático pueden diferir de verdad.
 *
 *     php artisan db:seed --class=Database\\Seeders\\StretchFilmFichaSeeder
 */
class StretchFilmFichaSeeder extends Seeder
{
    protected const SKU = 'CM_00001';

    /** Presentación => [medida corta, ficha técnica] */
    protected const VARIANTES = [
        'Manual 4,7 kg' => [
            '500 mm × 370 m',
            "Dimensiones del rollo
             Color: Transparente
             Ancho: 500 mm ±5 mm
             Longitud envolvente: 370 m
             Espesor: 0,023 mm ±10%
             Peso bruto: 4,7 kg
             Estiramiento mínimo: 150 a 200 %
             Tipo de aplicación: Manual
             Dimensiones del cono (tubo)
             Tipo de cono: Cartón 6 mm
             Diámetro: 77,4 mm ±0,4 mm
             Peso: 610 g ±10%
             Longitud: 510 mm ±1 mm",
        ],

        'Automático 15,7 kg' => [
            '500 mm',
            "Dimensiones del rollo
             Color: Transparente
             Ancho: 500 mm ±5 mm
             Espesor: 0,023 mm ±10%
             Peso bruto: 15,8 kg
             Estiramiento mínimo: 250 %
             Tipo de aplicación: Máquina
             Dimensiones del cono (tubo)
             Tipo de cono: Cartón 8 mm
             Diámetro: 77,4 mm ±0,4 mm
             Peso: 610 g ±10%
             Longitud: 510 mm ±1 mm",
        ],
    ];

    public function run(): void
    {
        $producto = Product::where('sku', self::SKU)->with('variantes')->first();

        if (! $producto) {
            $this->command?->warn('No existe el producto '.self::SKU.': se salta.');

            return;
        }

        foreach (self::VARIANTES as $presentacion => [$medida, $ficha]) {
            // Se busca por el valor del eje de identidad y no por id: los ids
            // cambian si alguien recrea las variantes, la presentación no.
            $variante = $producto->variantes->first(
                fn ($v) => (((array) $v->valores)['Presentación'] ?? null) === $presentacion
            );

            if (! $variante) {
                $this->command?->warn("No existe la variante «{$presentacion}»: se salta.");

                continue;
            }

            $variante->forceFill([
                'medida'          => $medida,
                'caracteristicas' => FichaTecnica::desdeTexto($ficha),
            ])->save();

            $this->command?->info("{$presentacion}: medida «{$medida}», ".count($variante->caracteristicas).' datos de ficha.');
        }
    }
}
