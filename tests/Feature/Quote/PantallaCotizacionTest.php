<?php

use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Product\Models\Product;
use Webkul\Quote\Repositories\QuoteRepository;

/**
 * Tarea 8: la cotización en pantalla — códigos en la fila del ítem y
 * condiciones de entrega propuestas.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->persona = app(PersonRepository::class)->create([
        'entity_type' => 'persons',
        'name'        => 'Pantalla '.uniqid(),
    ]);

    $this->bolsas = Product::where('sku', 'CM_00002')->first();
    $this->tapas = Product::where('sku', 'CM_00004')->first();

    // `lugar_entrega` es un atributo select: el formulario manda el ID de la
    // opción, no el texto.
    $this->lugares = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
        ->getAttributeByCode('lugar_entrega', 'quotes')
        ->options->pluck('id', 'name');

    $this->quotes = [];
});

/** Una cotización cualquiera que exista, para probar la pantalla de edición. */
function quoteExistente(): int
{
    return (int) DB::table('quotes')->min('id');
}

afterEach(function () {
    foreach ($this->quotes ?? [] as $id) {
        DB::table('quote_items')->where('quote_id', $id)->delete();
        DB::table('attribute_values')->where('entity_type', 'quotes')->where('entity_id', $id)->delete();
        DB::table('quotes')->where('id', $id)->delete();
    }

    if (isset($this->persona)) {
        DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $this->persona->id)->delete();
        DB::table('persons')->where('id', $this->persona->id)->delete();
    }
});

it('el alta renderiza el selector de variante y los campos de código', function () {
    $html = test()->get(route('admin.quotes.create'))->assertOk()->getContent();

    expect($html)->toContain('[variante_id]')
        ->and($html)->toContain('[codigo_tr4]')
        ->and($html)->toContain('[codigo_barra]')
        ->and($html)->toContain('[medida]')
        ->and($html)->toContain('[unidad]')
        ->and($html)->toContain('elegirVariante')
        ->and($html)->toContain('proponerEntrega');
});

it('el buscador de productos entrega variantes y condiciones', function () {
    // Es lo que alimenta la fila: sin esto el select queda vacío.
    $d = collect(test()->getJson(route('admin.products.search').'?query=Magia')->json('data'))
        ->firstWhere('sku', 'CM_00002');

    expect($d['variantes'])->toHaveCount(5);
    expect($d['variantes'][1]['codigo_tr4'])->toBe('020117-0035');
    expect($d['entrega']['lugar'])->toBe('Puesto almacén cliente');

    $tapas = collect(test()->getJson(route('admin.products.search').'?query=Tapas')->json('data'))
        ->firstWhere('sku', 'CM_00004');

    expect($tapas['entrega']['lugar'])->toBe('Recoge en planta');
    expect(collect($tapas['variantes'])->firstWhere('etiqueta', 'Con impresión')['tiempo_entrega'])->toBe('20 días');
});

it('guarda una cotización con la variante elegida en el formulario', function () {
    $variante = $this->bolsas->variantes->firstWhere('codigo_tr4', '020117-0035');

    test()->post(route('admin.quotes.store'), [
        'entity_type'    => 'quotes',
        'subject'        => $asunto = 'Desde pantalla '.uniqid(),
        'person_id'      => $this->persona->id,
        'user_id'        => getDefaultAdmin()->id,
        'expired_at'     => now()->addDays(10)->toDateString(),
        'sub_total'      => 325,
        'grand_total'    => 325,
        'forma_pago'     => null,
        'tiempo_entrega' => 'Inmediata',
        'lugar_entrega'  => $this->lugares['Puesto almacén cliente'],
        'items'          => [
            'item_0' => [
                'product_id'  => $this->bolsas->id,
                'variante_id' => $variante->id,
                'quantity'    => 25,
                'price'       => 13,
                'total'       => 325,
            ],
        ],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();

    expect($quote)->not->toBeNull('el store no creó la cotización');

    $this->quotes[] = $quote->id;

    $item = DB::table('quote_items')->where('quote_id', $quote->id)->first();

    expect($item->codigo_tr4)->toBe('020117-0035');
    expect($item->codigo_barra)->toBe('7779970880037');
    expect($item->medida)->toBe('(65×80) cm');
    expect($item->unidad)->toBe('PZA');
    expect($quote->tiempo_entrega)->toBe('Inmediata');

    // Un atributo select guarda el ID de la opción, no el texto — igual que
    // `marca`. El PDF lo resuelve a nombre con Opciones::nombre().
    expect((int) $quote->lugar_entrega)->toBe($this->lugares['Puesto almacén cliente']);
});

it('respeta los códigos que el asesor escribió a mano', function () {
    // Hules todavía no tiene TR4 cargado: el asesor lo escribe en la fila.
    $hules = Product::where('sku', 'CM_00003')->first();

    test()->post(route('admin.quotes.store'), [
        'entity_type' => 'quotes',
        'subject'     => $asunto = 'A mano '.uniqid(),
        'person_id'   => $this->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => 260,
        'grand_total' => 260,
        'items'       => [
            'item_0' => [
                'product_id'  => $hules->id,
                'variante_id' => $hules->variantes->firstWhere('valores->Color', 'Negro')?->id
                    ?? $hules->variantes[1]->id,
                'codigo_tr4'  => '030222-0001',
                'quantity'    => 1,
                'price'       => 260,
                'total'       => 260,
            ],
        ],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();

    expect($quote)->not->toBeNull('el store no creó la cotización');

    $this->quotes[] = $quote->id;

    expect(DB::table('quote_items')->where('quote_id', $quote->id)->value('codigo_tr4'))->toBe('030222-0001');
});

it('las condiciones que el asesor guarda son las que quedan', function () {
    // La propuesta es una propuesta: si la cambió, manda la suya.
    test()->post(route('admin.quotes.store'), [
        'entity_type'    => 'quotes',
        'subject'        => $asunto = 'Condiciones '.uniqid(),
        'person_id'      => $this->persona->id,
        'user_id'        => getDefaultAdmin()->id,
        'expired_at'     => now()->addDays(10)->toDateString(),
        'sub_total'      => 0,
        'grand_total'    => 0,
        'tiempo_entrega' => '5 días hábiles',
        'lugar_entrega'  => $this->lugares['A domicilio'],
        'observaciones'  => 'Escenario #3 compra mínima 3 bultos por medida',
        'items'          => ['item_0' => ['product_id' => $this->tapas->id, 'quantity' => 1, 'price' => 0, 'total' => 0]],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();

    expect($quote)->not->toBeNull('el store no creó la cotización');

    $this->quotes[] = $quote->id;

    expect($quote->tiempo_entrega)->toBe('5 días hábiles');
    expect((int) $quote->lugar_entrega)->toBe($this->lugares['A domicilio']);
    expect($quote->observaciones)->toContain('Escenario #3');
});

it('el nombre de la opción se resuelve para mostrarla', function () {
    // Un select guarda el id; el PDF y el inbox necesitan el texto.
    $id = $this->lugares['Recoge en planta'];

    expect(\Webkul\Product\Support\Opciones::nombre($id))->toBe('Recoge en planta');
    expect(\Webkul\Product\Support\Opciones::nombre('Texto libre'))->toBe('Texto libre');
    expect(\Webkul\Product\Support\Opciones::nombre(null, 'Inmediata'))->toBe('Inmediata');
    expect(\Webkul\Product\Support\Opciones::nombre(99999999, 'sin dato'))->toBe('sin dato');
});

it('ya no muestra la sección de dirección', function () {
    // El formato RG-VCO-005 no la lleva: la cabecera es razón social, NIT,
    // sucursal y orden de compra.
    foreach ([route('admin.quotes.create'), route('admin.quotes.edit', quoteExistente())] as $url) {
        $html = test()->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('name="billing_address')
            ->and($html)->not->toContain('name="shipping_address');
    }
});

it('los códigos son columnas editables de la tabla', function () {
    $html = test()->get(route('admin.quotes.create'))->assertOk()->getContent();

    // Cabecera y celda, no dentro de la celda del producto.
    expect(substr_count($html, 'COD TR4'))->toBeGreaterThanOrEqual(2);

    foreach (['medida', 'codigo_tr4', 'codigo_barra', 'unidad'] as $campo) {
        expect($html)->toContain("[{$campo}]");
    }
});

it('la razón social se guarda en la cotización', function () {
    test()->post(route('admin.quotes.store'), [
        'entity_type'  => 'quotes',
        'subject'      => $asunto = 'Razón social '.uniqid(),
        'person_id'    => $this->persona->id,
        'user_id'      => getDefaultAdmin()->id,
        'expired_at'   => now()->addDays(10)->toDateString(),
        'sub_total'    => 0,
        'grand_total'  => 0,
        'razon_social' => 'GRUPO QUIROZ HOLDING',
        'items'        => ['item_0' => ['product_id' => $this->tapas->id, 'quantity' => 1, 'price' => 0, 'total' => 0]],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();

    expect($quote)->not->toBeNull();

    $this->quotes[] = $quote->id;

    // El formato separa CLIENTE (la persona) de RAZÓN SOCIAL (a quién se
    // factura): no siempre coinciden.
    expect($quote->razon_social)->toBe('GRUPO QUIROZ HOLDING');
    expect($quote->person->name)->not->toBe('GRUPO QUIROZ HOLDING');
});

it('la tabla de ítems tiene tantas celdas como encabezados', function () {
    /*
     * Regresión: al pasar los códigos de la celda del producto a columnas, la
     * vista de edición quedó con los <th> nuevos pero SIN los <td>: 12
     * encabezados contra 8 celdas, y la tabla se veía corrida. El HTML era
     * válido, así que nada fallaba — solo se veía mal.
     */
    foreach ([route('admin.quotes.create'), route('admin.quotes.edit', quoteExistente())] as $url) {
        $html = test()->get($url)->assertOk()->getContent();

        $head = substr($html, $i = strpos($html, 'id="v-quote-item-list-template"'), strpos($html, '</thead>', $i) - $i);
        $fila = substr($html, $j = strpos($html, 'id="v-quote-item-template"'), strpos($html, '</script>', $j) - $j);

        // El <tr> de la fila también lleva scope="row": por eso el -1.
        expect(substr_count($fila, 'scope="row"') - 1)
            ->toBe(substr_count($head, 'scope="col"'), 'th y td no coinciden en '.$url);
    }
});

it('la tabla de ítems es más ancha que el mínimo del componente', function () {
    // Doce columnas en 800px dejan los códigos ilegibles.
    $html = test()->get(route('admin.quotes.create'))->assertOk()->getContent();

    expect($html)->toContain('min-w-[1100px]')
        ->and($html)->toContain('overflow-x-auto');
});

it('las clases de la tabla de ítems existen en el CSS compilado del admin', function () {
    /*
     * El admin tiene su propio build de Tailwind y hace tree-shaking: una clase
     * nueva en un blade NO existe hasta correr `npm run build` en
     * packages/Webkul/Admin. Sin eso el HTML la lleva y no pasa nada — que es
     * exactamente lo que hizo que la tabla siguiera con el padding viejo.
     *
     * Ya nos pasó con `border-2` del dropzone. Este test lo detecta.
     */
    $css = collect(glob(public_path('admin/build/assets/*.css')))
        ->map(fn ($f) => file_get_contents($f))
        ->implode('');

    expect($css)->not->toBeEmpty('falta el build del admin: npm run build en packages/Webkul/Admin');

    // `!px-2` y `!py-2` van por celda (no como variante [&_td] sobre la tabla,
    // que perdía contra el px-6 del componente por orden en el CSS).
    foreach (['1100px', '\\!px-2', '\\!py-2', 'outline-brandColor', 'h-8'] as $clase) {
        expect(str_contains($css, $clase))->toBeTrue("la clase «{$clase}» no está compilada; corré npm run build en packages/Webkul/Admin");
    }
});

it('la razón social se completa con el nombre del cliente si viene vacía', function () {
    test()->post(route('admin.quotes.store'), [
        'entity_type' => 'quotes',
        'subject'     => $asunto = 'Sin razón social '.uniqid(),
        'person_id'   => $this->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => 0,
        'grand_total' => 0,
        'items'       => ['item_0' => ['product_id' => $this->tapas->id, 'quantity' => 1, 'price' => 0, 'total' => 0]],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();

    expect($quote)->not->toBeNull();

    $this->quotes[] = $quote->id;

    expect($quote->razon_social)->toBe($this->persona->name);
});

it('no pisa la razón social que el asesor escribió', function () {
    // Es el caso que justifica que el campo exista: se cotiza a una persona y
    // se factura a la empresa de su grupo.
    test()->post(route('admin.quotes.store'), [
        'entity_type'  => 'quotes',
        'subject'      => $asunto = 'Con razón social '.uniqid(),
        'person_id'    => $this->persona->id,
        'user_id'      => getDefaultAdmin()->id,
        'expired_at'   => now()->addDays(10)->toDateString(),
        'sub_total'    => 0,
        'grand_total'  => 0,
        'razon_social' => 'GRUPO QUIROZ HOLDING',
        'items'        => ['item_0' => ['product_id' => $this->tapas->id, 'quantity' => 1, 'price' => 0, 'total' => 0]],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();

    $this->quotes[] = $quote->id;

    expect($quote->razon_social)->toBe('GRUPO QUIROZ HOLDING');
});

it('la descripción del ítem sale del nombre del ERP de la variante', function () {
    // En el PDF, cinco líneas que digan «Bolsas Magia Verde» no le dicen al
    // cliente cuál es cuál.
    $variante = $this->bolsas->variantes->firstWhere('codigo_tr4', '020117-0035');

    test()->post(route('admin.quotes.store'), [
        'entity_type' => 'quotes',
        'subject'     => $asunto = 'Descripción ERP '.uniqid(),
        'person_id'   => $this->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => 325,
        'grand_total' => 325,
        'items'       => ['item_0' => [
            'product_id'  => $this->bolsas->id,
            'variante_id' => $variante->id,
            'quantity'    => 25, 'price' => 13, 'total' => 325,
        ]],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();

    $this->quotes[] = $quote->id;

    expect(DB::table('quote_items')->where('quote_id', $quote->id)->value('name'))
        ->toBe('BSA MULTIUSO MAGIA VERDE 50L 10UN');
});

it('respeta la descripción que el asesor escribió en el formulario', function () {
    $variante = $this->bolsas->variantes->firstWhere('codigo_tr4', '020117-0035');

    test()->post(route('admin.quotes.store'), [
        'entity_type' => 'quotes',
        'subject'     => $asunto = 'Descripción a mano '.uniqid(),
        'person_id'   => $this->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => 325,
        'grand_total' => 325,
        'items'       => ['item_0' => [
            'product_id'  => $this->bolsas->id,
            'variante_id' => $variante->id,
            'name'        => 'Bolsa reforzada 50 L — pedido especial',
            'quantity'    => 25, 'price' => 13, 'total' => 325,
        ]],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();

    $this->quotes[] = $quote->id;

    expect(DB::table('quote_items')->where('quote_id', $quote->id)->value('name'))
        ->toBe('Bolsa reforzada 50 L — pedido especial');
});

it('sin variante con nombre de ERP usa el nombre del producto', function () {
    test()->post(route('admin.quotes.store'), [
        'entity_type' => 'quotes',
        'subject'     => $asunto = 'Sin ERP '.uniqid(),
        'person_id'   => $this->persona->id,
        'user_id'     => getDefaultAdmin()->id,
        'expired_at'  => now()->addDays(10)->toDateString(),
        'sub_total'   => 0, 'grand_total' => 0,
        'items'       => ['item_0' => ['product_id' => $this->tapas->id, 'quantity' => 1, 'price' => 0, 'total' => 0]],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();

    $this->quotes[] = $quote->id;

    expect(DB::table('quote_items')->where('quote_id', $quote->id)->value('name'))->toBe($this->tapas->name);
});

it('el formulario tiene el campo de descripción y el autorelleno de razón social', function () {
    $html = test()->get(route('admin.quotes.create'))->assertOk()->getContent();

    expect($html)->toContain('[name]')
        ->and($html)->toContain('descripcionEditada')
        ->and($html)->toContain('nombreDelCliente');
});

it('cumple la auditoría UI/UX del 14/09', function () {
    $html = test()->get(route('admin.quotes.create'))->assertOk()->getContent();

    // Nombre accesible en los inputs de la fila: el placeholder desaparece al
    // escribir y un lector de pantalla no sabe qué campo es.
    foreach (['medida', 'codigo_tr4', 'codigo_barra', 'unidad', 'name', 'variante_id'] as $campo) {
        $i = strpos($html, "[{$campo}]`\"");

        // toContain es variádico en Pest: el segundo argumento sería otro
        // needle, no un mensaje.
        expect($i)->not->toBeFalse();
        expect(str_contains(substr($html, $i, 400), ':aria-label='))->toBeTrue("{$campo} sin aria-label");
    }

    // Código de barra: teclado numérico en móvil.
    expect($html)->toContain('inputmode="numeric"');

    // "Agregar producto" y "Quitar" son botones de verdad, no spans/iconos.
    expect($html)->not->toMatch('/<span[^>]*@click="addProduct"/');
    expect($html)->toMatch('/<button[^>]*@click="addProduct"/');

    // Un solo envío por formulario.
    expect($html)->toContain('data-una-vez');

    // Reducir movimiento se respeta en todo el admin.
    $css = collect(glob(public_path('admin/build/assets/*.css')))->map(fn ($f) => file_get_contents($f))->implode('');

    expect(str_contains($css, 'prefers-reduced-motion'))->toBeTrue();
});

it('las etiquetas de la fila de ítem están traducidas, no son claves crudas', function () {
    /*
     * Reportado: el placeholder decía «admin::app.quotes.create.descripcion-item».
     * Las claves estaban en el bloque `index.pdf` del idioma en vez de `create`.
     */
    $html = test()->get(route('admin.quotes.create'))->assertOk()->getContent();

    expect($html)->not->toContain('admin::app.quotes.create.')
        ->and($html)->toContain('Descripción para el PDF')
        ->and($html)->toContain('Quitar el producto');
});

it('las celdas de la tabla de ítems son compactas de verdad', function () {
    /*
     * Reportado: las celdas seguían con px-6 py-4. La variante arbitraria
     * [&_td]:!px-2 sobre la tabla perdía contra el !px-6 del componente por
     * orden en el CSS. Ahora cada celda lleva su propia clase.
     */
    $html = test()->get(route('admin.quotes.create'))->assertOk()->getContent();

    $fila = substr($html, $i = strpos($html, 'id="v-quote-item-template"'), strpos($html, '</script>', $i) - $i);

    // Todos los <td> de la fila llevan !px-2; ninguno queda solo con px-6.
    preg_match_all('/<td[^>]*class="([^"]*)"/', $fila, $m);

    expect($m[1])->not->toBeEmpty();

    foreach ($m[1] as $clases) {
        expect($clases)->toContain('!px-2');
    }
});

it('el guard anti-doble-envío no impide el envío', function () {
    /*
     * Reportado: «no se están guardando los productos». El guard deshabilitaba
     * el botón en fase de captura, antes de que VeeValidate procesara el
     * submit, y el formulario nunca salía. Ahora se difiere.
     */
    $html = test()->get(route('admin.quotes.create'))->assertOk()->getContent();

    $script = substr($html, strpos($html, "addEventListener('submit'"), 900);

    expect($script)->toContain('setTimeout(')
        ->and($script)->not->toContain('}, true);');
});

it('hay un solo selector de variante por fila', function () {
    // Reportado: aparecían dos «Elegir variante…». Al reordenar la celda se
    // insertó la copia sin borrar el original.
    foreach ([route('admin.quotes.create'), route('admin.quotes.edit', quoteExistente())] as $url) {
        $html = test()->get($url)->assertOk()->getContent();

        $fila = substr($html, $i = strpos($html, 'id="v-quote-item-template"'), strpos($html, '</script>', $i) - $i);

        expect(substr_count($fila, '[variante_id]'))->toBe(1, $url);
        expect(substr_count($fila, '[name]'))->toBe(1, $url);
    }
});

/*
| 14/09: "los productos de una cotización no se están mostrando". Los ítems
| llegaban al HTML, pero la fila evaluaba `variantes.length` sin que
| `variantes` existiera en data(): en una fila nueva `product_id` es null y el
| && corta antes; en una fila guardada no, y el undefined abortaba el render
| de TODA la lista. Solo se veía al editar una cotización con productos.
*/
it('al editar, los ítems guardados se ven y traen las variantes de su producto', function () {
    $variante = $this->bolsas->variantes->firstWhere('codigo_tr4', '020117-0035');

    test()->post(route('admin.quotes.store'), [
        'entity_type'    => 'quotes',
        'subject'        => $asunto = 'Editar con items '.uniqid(),
        'person_id'      => $this->persona->id,
        'user_id'        => getDefaultAdmin()->id,
        'expired_at'     => now()->addDays(10)->toDateString(),
        'sub_total'      => 13,
        'grand_total'    => 13,
        'tiempo_entrega' => 'Inmediata',
        'lugar_entrega'  => $this->lugares['Puesto almacén cliente'],
        'items'          => [
            'item_0' => ['product_id' => $this->bolsas->id, 'variante_id' => $variante->id, 'quantity' => 1, 'price' => 13, 'total' => 13],
        ],
    ])->assertRedirect();

    $quote = app(QuoteRepository::class)->getModel()->newQuery()->where('subject', $asunto)->first();
    $this->quotes[] = $quote->id;

    $html = test()->get(route('admin.quotes.edit', $quote->id))->assertOk()->getContent();

    // El componente de fila declara `variantes` desde el arranque.
    preg_match("/app\.component\('v-quote-item',.*?data\(\)\s*\{.*?return\s*\{(.*?)\}\s*\},/s", $html, $m);
    expect($m[1] ?? '')->toContain('variantes:');

    // Y cada ítem guardado llega con las variantes de su producto, para que el
    // select no aparezca vacío al editar.
    preg_match('/adjustmentAmount: 0,\s*products: (\[.*?\]),\n/s', $html, $m);
    $items = json_decode($m[1] ?? '[]', true);

    expect($items)->toHaveCount(1);
    expect($items[0]['codigo_tr4'])->toBe('020117-0035');
    expect(collect($items[0]['variantes'])->pluck('id'))->toContain($variante->id);
    expect($items[0]['entrega']['tiempo'])->not->toBeNull();
});
