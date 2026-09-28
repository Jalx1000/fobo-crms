<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductMedia;
use Webkul\Product\Support\Variantes;

/**
 * Asigna cada ficha técnica en PDF de Bolsas Magia Verde a su tamaño.
 *
 * Los cinco PDFs se cargaron el 18/09 colgando del producto, y el orden de la
 * galería no seguía los tamaños (el segundo era el de 200 L). Se leyó el
 * contenido de cada uno —«Capacidad: 50L», «Ancho de bolsa: 65cm»— y coincide
 * con la medida de cada variante, así que la asignación es por archivo.
 *
 * Se identifica por la ruta del archivo y no por id: es lo que no cambia si se
 * reimporta la tabla. Idempotente.
 *
 *     php artisan db:seed --class=Database\\Seeders\\MagiaVerdeFichasSeeder
 */
class MagiaVerdeFichasSeeder extends Seeder
{
    protected const SKU = 'CM_00002';

    /** Archivo => Tamaño, según la capacidad impresa en cada PDF. */
    protected const FICHAS = [
        'productos/6/QEAQNtxVHk0l5Xr9obIUsTXkMpZVsWMVqgRdQUXQ.pdf' => '35 L',
        'productos/6/0EtZYavP9J1uneTIU5trDHknFAYRXMuPhEpTT8Gl.pdf' => '50 L',
        'productos/6/Bwt7XS34X3X7oKjFpeDEhL33gHpncKBUpkSEDl3h.pdf' => '75 L',
        'productos/6/RY6ZkZoSbbVlsUgwZ5uqoR2IynTQfDqx9Rog5mGG.pdf' => '140 L',
        'productos/6/4LbgEQBxKgTtoJjFyYOFMAMGL8Jhh8Rlg7bJGaCD.pdf' => '200 L',
    ];

    public function run(): void
    {
        $producto = Product::where('sku', self::SKU)->with('variantes')->first();

        if (! $producto) {
            $this->command?->warn('No existe '.self::SKU.': se salta.');

            return;
        }

        foreach (self::FICHAS as $path => $tamano) {
            $media = ProductMedia::where('product_id', $producto->id)->where('path', $path)->first();

            $variante = $producto->variantes->first(fn ($v) => (($v->valores['Tamaño'] ?? null)) === $tamano);

            if (! $media || ! $variante) {
                $this->command?->warn("{$tamano}: ".(! $media ? 'no está el PDF' : 'no existe la variante').', se salta.');

                continue;
            }

            $media->forceFill(['variante_id' => $variante->id])->save();

            $this->command?->info(Variantes::etiqueta($producto, $variante).' → '.basename($path));
        }
    }
}
