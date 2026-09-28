<?php

use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Quote\Repositories\QuoteRepository;

/**
 * Editar una cotización cuyo producto ya no está en el catálogo.
 *
 * Reportado en producción: PUT admin/quotes/edit/8 devolvía 404 con
 * «No query results for model [Product] 12». El 404 aparecía sobre una página
 * que sí existe, así que no había forma de adivinar la causa.
 *
 * `quote_items` guarda `sku` y `name` copiados del producto —es una foto de lo
 * que se cotizó, no un puntero— y `product_id` no tiene FK, así que borrar un
 * producto deja huérfanos en silencio y rompe para siempre la edición de toda
 * cotización que lo incluyera.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->persona = app(PersonRepository::class)->create([
        'entity_type' => 'persons',
        'name'        => 'Huérfano '.uniqid(),
    ]);

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Producto a borrar '.uniqid(),
        'sku'         => 'TEST-DEL-'.uniqid(),
        'price'       => 10,
        'quantity'    => 1,
    ]);

    $this->cotizacion = app(QuoteRepository::class)->create([
        'entity_type' => 'quotes',
        'subject'     => 'Con producto borrado '.uniqid(),
        'person_id'   => $this->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(7)->toDateString(),
        'sub_total'   => 10,
        'grand_total' => 10,
        'items'       => [[
            'product_id' => $this->producto->id,
            'quantity'   => 1,
            'price'      => 10,
            'total'      => 10,
        ]],
    ]);
});

afterEach(function () {
    foreach ([$this->cotizacion?->id] as $id) {
        if (! $id) {
            continue;
        }

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

it('se puede editar aunque el producto ya no exista', function () {
    $item = DB::table('quote_items')->where('quote_id', $this->cotizacion->id)->first();

    $skuOriginal = $item->sku;
    $nombreOriginal = $item->name;

    // El producto desaparece del catálogo después de haberse cotizado.
    DB::table('products')->where('id', $this->producto->id)->delete();

    test()->put(route('admin.quotes.update', $this->cotizacion->id), [
        'entity_type' => 'quotes',
        'subject'     => 'Editada sin producto',
        'person_id'   => $this->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(14)->toDateString(),
        'sub_total'   => 20,
        'grand_total' => 20,
        'items'       => [
            $item->id => [
                'product_id' => $this->producto->id,
                'quantity'   => 2,
                'price'      => 10,
                'total'      => 20,
            ],
        ],
    ])->assertRedirect();

    $fresca = app(QuoteRepository::class)->find($this->cotizacion->id);

    expect($fresca->subject)->toBe('Editada sin producto');
    expect((float) $fresca->grand_total)->toBe(20.0);

    // Lo que se le cotizó al cliente NO se pierde: sku y nombre siguen ahí.
    $itemFresco = DB::table('quote_items')->where('id', $item->id)->first();

    expect($itemFresco->sku)->toBe($skuOriginal);
    expect($itemFresco->name)->toBe($nombreOriginal);
    expect((int) $itemFresco->quantity)->toBe(2);
});

it('sigue copiando sku y nombre cuando el producto sí existe', function () {
    // El comportamiento normal no cambia: renombrar el producto y guardar la
    // cotización actualiza la foto del ítem.
    $item = DB::table('quote_items')->where('quote_id', $this->cotizacion->id)->first();

    DB::table('products')->where('id', $this->producto->id)->update(['name' => 'Nombre nuevo']);

    test()->put(route('admin.quotes.update', $this->cotizacion->id), [
        'entity_type' => 'quotes',
        'subject'     => $this->cotizacion->subject,
        'person_id'   => $this->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(7)->toDateString(),
        'sub_total'   => 10,
        'grand_total' => 10,
        'items'       => [
            $item->id => ['product_id' => $this->producto->id, 'quantity' => 1, 'price' => 10, 'total' => 10],
        ],
    ])->assertRedirect();

    expect(DB::table('quote_items')->where('id', $item->id)->value('name'))->toBe('Nombre nuevo');
});

it('no crea un ítem fantasma para un producto que no existe', function () {
    // Agregar un ítem nuevo apuntando a un producto borrado no tiene de dónde
    // sacar sku ni nombre: se descarta en vez de guardar una fila vacía.
    $repo = app(\Webkul\Quote\Repositories\QuoteItemRepository::class);

    expect($repo->create([
        'quote_id'   => $this->cotizacion->id,
        'product_id' => 99999999,
        'quantity'   => 1,
        'price'      => 5,
    ]))->toBeNull();
});
