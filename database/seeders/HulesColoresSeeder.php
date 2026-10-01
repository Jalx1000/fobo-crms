<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductPrice;
use Webkul\Product\Models\ProductVariante;

/**
 * Los cinco colores de Hules (CM_00003) que faltaban, confirmados por el
 * equipo del agente el 18/09/2026.
 *
 * El catálogo tenía tres valores en el eje Color: Transparente, Negro y un
 * comodín «Colores» a Bs 460 con la nota «aplica a los 5 colores que no son
 * transparente ni negro; falta confirmar cuáles son». Ya se sabe cuáles:
 * Amarillo, Azul, Blanco, Naranja y Rojo. El agente rutea por el valor EXACTO
 * del eje, así que «Colores» nunca casaba con «Blanco» y el cliente se quedaba
 * sin precio.
 *
 * Cada color pasa a ser su propia fila de precio y su propia variante, al mismo
 * Bs 460/rollo del comodín. El comodín se retira: nadie lo cotizó (cero ítems
 * de cotización lo referencian) y dejarlo daría dos maneras de pedir lo mismo.
 *
 * Idempotente: identifica por el valor del eje.
 *
 *     php artisan db:seed --class=Database\\Seeders\\HulesColoresSeeder
 */
class HulesColoresSeeder extends Seeder
{
    protected const SKU = 'CM_00003';

    protected const COMODIN = 'Colores';

    protected const COLORES = ['Amarillo', 'Azul', 'Blanco', 'Naranja', 'Rojo'];

    public function run(): void
    {
        $producto = Product::where('sku', self::SKU)->with(['precios', 'variantes'])->first();

        if (! $producto) {
            $this->command?->warn('No existe el producto '.self::SKU.': se salta.');

            return;
        }

        $comodin = $producto->precios->first(fn ($f) => ($f->valores['Color'] ?? null) === self::COMODIN);

        // El precio de los colores es el del comodín. Si alguien ya lo retiró,
        // se usa el último conocido.
        $precio = $comodin ? (float) $comodin->precio : 460.0;
        $unidad = $comodin?->unidad ?: 'por rollo';

        DB::transaction(function () use ($producto, $comodin, $precio, $unidad) {
            $orden = (int) $producto->precios->max('orden');
            $ordenVariante = (int) $producto->variantes->max('orden');

            foreach (self::COLORES as $color) {
                $existePrecio = $producto->precios->first(fn ($f) => ($f->valores['Color'] ?? null) === $color);

                if (! $existePrecio) {
                    ProductPrice::create([
                        'product_id' => $producto->id,
                        'valores'    => ['Color' => $color],
                        'precio'     => $precio,
                        'unidad'     => $unidad,
                        'orden'      => ++$orden,
                    ]);
                }

                $existeVariante = $producto->variantes->first(fn ($v) => ($v->valores['Color'] ?? null) === $color);

                if (! $existeVariante) {
                    ProductVariante::create([
                        'product_id' => $producto->id,
                        'valores'    => ['Color' => $color],
                        'unidad'     => 'PZA',
                        'orden'      => ++$ordenVariante,
                    ]);
                }

                $this->command?->info("{$color}: Bs {$precio} {$unidad}");
            }

            // El comodín se retira solo si ninguna cotización lo usó.
            $usado = DB::table('quote_items')
                ->where('product_id', $producto->id)
                ->where('variante', 'like', '%'.self::COMODIN.'%')
                ->exists();

            if ($usado) {
                $this->command?->warn('«Colores» se conserva: hay cotizaciones emitidas que lo usan.');

                return;
            }

            $comodin?->delete();

            $producto->variantes
                ->first(fn ($v) => ($v->valores['Color'] ?? null) === self::COMODIN)
                ?->delete();

            $this->command?->info('«Colores» retirado: reemplazado por los cinco colores.');
        });
    }
}
