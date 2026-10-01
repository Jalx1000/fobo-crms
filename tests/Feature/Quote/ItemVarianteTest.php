<?php

use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\ProductVarianteRepository;
use Webkul\Quote\Repositories\QuoteRepository;

/**
 * Tarea 7: el ítem de cotización guarda una FOTO de la variante.
 *
 * Una cotización es un documento emitido, no una vista sobre el catálogo: si
 * mañana alguien corrige un TR4 o borra el producto, lo que el cliente tiene en
 * la mano no puede cambiar.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->producto = app(ProductRepository::class)->create([
        'entity_type'    => 'products',
        'name'           => 'Foto variante '.uniqid(),
        'sku'            => 'TEST-FV-'.uniqid(),
        'price'          => 0,
        'quantity'       => 0,
        'ejes_precio'    => ['Tamaño', 'Canal'],
        'ejes_identidad' => ['Tamaño'],
    ]);

    app(ProductVarianteRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Tamaño' => '50 L'], 'codigo_tr4' => '020117-0035', 'codigo_barra' => '7779970880037', 'medida' => '(65×80) cm', 'unidad' => 'PZA', 'orden' => 0],
        ['valores' => ['Tamaño' => '75 L'], 'codigo_tr4' => '020117-0034', 'orden' => 1],
    ]);

    $this->persona = app(PersonRepository::class)->create([
        'entity_type' => 'persons',
        'name'        => 'Foto '.uniqid(),
    ]);

    $this->quotes = [];
});

afterEach(function () {
    foreach ($this->quotes ?? [] as $id) {
        DB::table('quote_items')->where('quote_id', $id)->delete();
        DB::table('attribute_values')->where('entity_type', 'quotes')->where('entity_id', $id)->delete();
        DB::table('quotes')->where('id', $id)->delete();
    }

    if (isset($this->producto)) {
        DB::table('products')->where('id', $this->producto->id)->delete();
    }

    if (isset($this->persona)) {
        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $this->persona->id)->delete();
        DB::table('persons')->where('id', $this->persona->id)->delete();
    }
});

function cotizacionCon(array $items): object
{
    $q = app(QuoteRepository::class)->create([
        'entity_type' => 'quotes',
        'subject'     => 'Foto variante '.uniqid(),
        'person_id'   => test()->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => 0,
        'grand_total' => 0,
        'items'       => $items,
    ]);

    return $q;
}

it('copia TR4, barra, medida y unidad de la variante elegida', function () {
    $q = cotizacionCon([[
        'product_id' => $this->producto->id,
        'criterios'  => ['Tamaño' => '50 L', 'Canal' => 'HORECA'],
        'quantity'   => 25,
        'price'      => 13,
        'total'      => 325,
    ]]);

    $this->quotes[] = $q->id;

    $item = DB::table('quote_items')->where('quote_id', $q->id)->first();

    expect($item->codigo_tr4)->toBe('020117-0035');
    expect($item->codigo_barra)->toBe('7779970880037');
    expect($item->medida)->toBe('(65×80) cm');
    expect($item->unidad)->toBe('PZA');
    expect(json_decode($item->variante, true))->toBe(['Tamaño' => '50 L']);
});

it('lo escrito a mano gana sobre la variante', function () {
    // El asesor completa el código de barra que todavía no está cargado, o
    // corrige uno puntual para un cliente.
    $q = cotizacionCon([[
        'product_id'   => $this->producto->id,
        'criterios'    => ['Tamaño' => '50 L'],
        'codigo_barra' => '9999999999999',
        'quantity'     => 1,
        'price'        => 1,
        'total'        => 1,
    ]]);

    $this->quotes[] = $q->id;

    $item = DB::table('quote_items')->where('quote_id', $q->id)->first();

    expect($item->codigo_barra)->toBe('9999999999999');
    expect($item->codigo_tr4)->toBe('020117-0035'); // el que no escribió, sí viene de la variante
});

it('una variante sin código no deja el ítem sin cotizar', function () {
    $q = cotizacionCon([[
        'product_id' => $this->producto->id,
        'criterios'  => ['Tamaño' => '75 L'],
        'quantity'   => 1,
        'price'      => 1,
        'total'      => 1,
    ]]);

    $this->quotes[] = $q->id;

    $item = DB::table('quote_items')->where('quote_id', $q->id)->first();

    expect($item->codigo_tr4)->toBe('020117-0034');
    expect($item->codigo_barra)->toBeNull();
    expect($item->unidad)->toBe('PZA');
});

it('la foto sobrevive a que cambien el código del producto', function () {
    $q = cotizacionCon([[
        'product_id' => $this->producto->id,
        'criterios'  => ['Tamaño' => '50 L'],
        'quantity'   => 1, 'price' => 1, 'total' => 1,
    ]]);

    $this->quotes[] = $q->id;

    // Alguien corrige el TR4 en el catálogo.
    app(ProductVarianteRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Tamaño' => '50 L'], 'codigo_tr4' => 'OTRO-CODIGO', 'orden' => 0],
    ]);

    expect(DB::table('quote_items')->where('quote_id', $q->id)->value('codigo_tr4'))->toBe('020117-0035');
});

it('la foto sobrevive a que borren el producto', function () {
    $q = cotizacionCon([[
        'product_id' => $this->producto->id,
        'criterios'  => ['Tamaño' => '50 L'],
        'quantity'   => 1, 'price' => 1, 'total' => 1,
    ]]);

    $this->quotes[] = $q->id;

    DB::table('products')->where('id', $this->producto->id)->delete();

    $item = DB::table('quote_items')->where('quote_id', $q->id)->first();

    expect($item->codigo_tr4)->toBe('020117-0035');
    expect($item->medida)->toBe('(65×80) cm');
});

it('sin criterios el ítem se crea igual, sin códigos', function () {
    // Ítems viejos y productos sin variantes siguen funcionando.
    $q = cotizacionCon([[
        'product_id' => $this->producto->id,
        'quantity'   => 1, 'price' => 1, 'total' => 1,
    ]]);

    $this->quotes[] = $q->id;

    $item = DB::table('quote_items')->where('quote_id', $q->id)->first();

    expect($item->codigo_tr4)->toBeNull();
    expect($item->sku)->toBe($this->producto->sku); // la foto del producto sí
});
