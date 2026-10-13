<?php

use Webkul\Product\Models\ProductPrice;
use Webkul\Product\Repositories\ProductPriceRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Support\ListaPrecios;

/**
 * Precio por variante.
 *
 * Lo que se protege acá es que el agente nunca cotice un número que el negocio
 * no cargó: ni el 0 de la columna `price`, ni el de la fila de al lado cuando el
 * cliente todavía no dijo para qué canal es.
 */
beforeEach(function () {
    actingAsSanctumAuthenticatedAdmin();

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Bolsas de prueba '.uniqid(),
        'sku'         => 'TEST-PR-'.uniqid(),
        'price'       => 0,
        'ejes_precio' => ['Tamaño', 'Canal'],
    ]);

    app(ProductPriceRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Tamaño' => '35 L', 'Canal' => 'TRADICIONAL'], 'precio' => 120, 'unidad' => 'por bulto', 'minimo' => '1 bulto', 'nota' => null, 'orden' => 0],
        ['valores' => ['Tamaño' => '35 L', 'Canal' => 'HORECA'],      'precio' => 110, 'unidad' => 'por bulto', 'minimo' => null, 'nota' => null, 'orden' => 1],
        ['valores' => ['Tamaño' => '50 L', 'Canal' => 'TRADICIONAL'], 'precio' => 150, 'unidad' => 'por bulto', 'minimo' => null, 'nota' => null, 'orden' => 2],
        ['valores' => ['Tamaño' => '50 L', 'Canal' => 'DISTRIBUIDOR'], 'precio' => null, 'unidad' => null, 'minimo' => null, 'nota' => 'Derivar al equipo comercial.', 'orden' => 3],
    ]);
});

afterEach(function () {
    // Se limpia acá y no al final del test: si el test falla, igual se borra.
    foreach (['producto', 'stretch'] as $prop) {
        if (! isset($this->{$prop})) {
            continue;
        }

        ProductPrice::where('product_id', $this->{$prop}->id)->delete();

        $this->{$prop}->newQuery()->whereKey($this->{$prop}->id)->delete();
    }
});

it('resuelve el precio cuando el agente manda todos los ejes', function () {
    $r = test()->postJson("/api/v1/productos/catalogo/{$this->producto->sku}/precio", [
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'TRADICIONAL'],
    ])->assertOk();

    expect($r->json('estado'))->toBe('resuelto');
    expect((float) $r->json('precio.precio'))->toBe(150.0);
    expect($r->json('precio.cotiza'))->toBeTrue();
});

it('tolera acentos y mayúsculas en el nombre del eje y en el valor', function () {
    // El agente escribe lo que el modelo generó, no lo que cargó el asesor.
    // Un acento de diferencia no puede convertirse en "no hay precio".
    $r = test()->postJson("/api/v1/productos/catalogo/{$this->producto->sku}/precio", [
        'criterios' => ['tamano' => '50l', 'canal' => 'tradicional'],
    ])->assertOk();

    expect($r->json('estado'))->toBe('resuelto');
    expect((float) $r->json('precio.precio'))->toBe(150.0);
});

it('no elige por el cliente cuando faltan ejes', function () {
    // Dos canales para 35 L: devolver el primero sería inventarle un canal.
    $r = test()->postJson("/api/v1/productos/catalogo/{$this->producto->sku}/precio", [
        'criterios' => ['Tamaño' => '35 L'],
    ])->assertOk();

    expect($r->json('estado'))->toBe('ambiguo');
    expect($r->json('faltan'))->toBe(['Canal']);
    expect($r->json('opciones.Canal'))->toEqualCanonicalizing(['TRADICIONAL', 'HORECA']);
});

it('no propone preguntar un eje que no discrimina', function () {
    // Solo hay un canal DISTRIBUIDOR y es de 50 L: preguntar el tamaño le haría
    // contestar al cliente algo que no cambia nada.
    $r = test()->postJson("/api/v1/productos/catalogo/{$this->producto->sku}/precio", [
        'criterios' => ['Canal' => 'DISTRIBUIDOR'],
    ])->assertOk();

    expect($r->json('estado'))->toBe('resuelto');
});

it('devuelve la fila sin precio con su nota en vez de esconderla', function () {
    // La combinación existe y a propósito no se cotiza. Si desapareciera, el
    // agente diría "no lo vendemos" en vez de derivar.
    $r = test()->postJson("/api/v1/productos/catalogo/{$this->producto->sku}/precio", [
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'DISTRIBUIDOR'],
    ])->assertOk();

    expect($r->json('estado'))->toBe('resuelto');
    expect($r->json('precio.precio'))->toBeNull();
    expect($r->json('precio.cotiza'))->toBeFalse();
    expect($r->json('precio.nota'))->toBe('Derivar al equipo comercial.');
});

it('avisa sin_datos en vez de acercar la combinación más parecida', function () {
    $r = test()->postJson("/api/v1/productos/catalogo/{$this->producto->sku}/precio", [
        'criterios' => ['Tamaño' => '200 L', 'Canal' => 'HORECA'],
    ])->assertOk();

    expect($r->json('estado'))->toBe('sin_datos');
    expect($r->json('ejes'))->toBe(['Tamaño', 'Canal']);
});

it('ignora criterios que no son ejes del producto', function () {
    // El agente manda lo que entendió de la charla; un dato de más no puede
    // hacer desaparecer un precio que sí existe.
    $r = test()->postJson("/api/v1/productos/catalogo/{$this->producto->sku}/precio", [
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'TRADICIONAL', 'Ciudad' => 'Santa Cruz'],
    ])->assertOk();

    expect($r->json('estado'))->toBe('resuelto');
});

it('nunca le muestra al agente el precio 0 de un producto de precio variable', function () {
    // Es el peor error posible: ofrecer bolsas a 0 Bs porque la columna `price`
    // quedó en cero al no usarse.
    $r = test()->getJson("/api/v1/productos/catalogo/{$this->producto->sku}")->assertOk();

    expect($r->json('precio'))->toBeNull();
    expect($r->json('precio_variable'))->toBeTrue();
    expect($r->json('ejes_precio'))->toBe(['Tamaño', 'Canal']);
    expect($r->json('precios'))->toHaveCount(4);
});

it('lista en el catálogo un producto que solo tiene precios y ningún adjunto', function () {
    $r = test()->getJson('/api/v1/productos/catalogo?q='.$this->producto->sku)->assertOk();

    expect(collect($r->json('data'))->pluck('sku'))->toContain($this->producto->sku);
});

it('un producto de precio fijo contesta el precio de la tabla', function () {
    $fijo = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Fijo '.uniqid(),
        'sku'         => 'TEST-PF-'.uniqid(),
        'price'       => 42.5,
    ]);

    $r = test()->postJson("/api/v1/productos/catalogo/{$fijo->sku}/precio")->assertOk();

    expect($r->json('estado'))->toBe('resuelto');
    expect((float) $r->json('precio.precio'))->toBe(42.5);

    $fijo->newQuery()->whereKey($fijo->id)->delete();
});

it('reemplazar la lista no deja filas viejas sueltas', function () {
    app(ProductPriceRepository::class)->reemplazar($this->producto->id, [
        ['valores' => ['Tamaño' => '75 L', 'Canal' => 'HORECA'], 'precio' => 200, 'orden' => 0],
    ]);

    expect(ProductPrice::where('product_id', $this->producto->id)->count())->toBe(1);
});

it('descarta la fila en blanco del formulario pero conserva la que solo no tiene precio', function () {
    $filas = ListaPrecios::normalizarFilas([
        ['valores' => ['Tamaño' => '35 L', 'Canal' => 'HORECA'], 'precio' => '110'],
        ['valores' => ['Tamaño' => '50 L', 'Canal' => 'DISTRIBUIDOR'], 'precio' => '', 'nota' => 'Derivar.'],
        ['valores' => ['Tamaño' => '', 'Canal' => ''], 'precio' => '999'],
    ], ['Tamaño', 'Canal']);

    expect($filas)->toHaveCount(2);
    expect($filas[1]['precio'])->toBeNull();
    expect($filas[1]['nota'])->toBe('Derivar.');
});

it('acepta la coma como separador decimal', function () {
    // El asesor escribe 927,50 porque así se escribe en Bolivia.
    $filas = ListaPrecios::normalizarFilas([
        ['valores' => ['Impresión' => 'Sin impresión'], 'precio' => '927,50'],
    ], ['Impresión']);

    expect($filas[0]['precio'])->toBe(927.5);
});

it('no guarda ejes cuando no hay ninguna fila cargada', function () {
    // Un producto que anuncia cotizarse por Tamaño y no tiene ningún tamaño le
    // hace prometer al agente una pregunta que no lleva a ningún precio.
    expect(ListaPrecios::normalizarEjes(['Tamaño', '', 'Tamaño']))->toBe(['Tamaño']);
    expect(ListaPrecios::normalizarEjes(['', '  ']))->toBeNull();
});

it('devuelve los valores en el orden de los ejes, no en el que los guardó MySQL', function () {
    // MySQL normaliza el orden de las claves de una columna JSON: {"Tamaño",
    // "Canal"} vuelve como {"Canal","Tamaño"} y la combinación se leería
    // «HORECA · 50 L» en vez de «50 L · HORECA».
    $r = test()->postJson("/api/v1/productos/catalogo/{$this->producto->sku}/precio", [
        'criterios' => ['Tamaño' => '50 L', 'Canal' => 'TRADICIONAL'],
    ])->assertOk();

    expect(array_keys((array) $r->json('precio.valores')))->toBe(['Tamaño', 'Canal']);
});

it('no ofrece opciones de un eje que el cliente ya contestó', function () {
    // Devolverlo invita al modelo a volver a preguntar lo mismo.
    $r = test()->postJson("/api/v1/productos/catalogo/{$this->producto->sku}/precio", [
        'criterios' => ['Tamaño' => '35 L'],
    ])->assertOk();

    expect($r->json('opciones'))->toHaveKey('Canal');
    expect($r->json('opciones'))->not->toHaveKey('Tamaño');
});

it('elige el tramo por volumen según la cantidad pedida', function () {
    $stretch = $this->stretch = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Stretch de prueba '.uniqid(),
        'sku'         => 'TEST-VOL-'.uniqid(),
        'price'       => 0,
        'ejes_precio' => ['Presentación', 'Cantidad'],
    ]);

    app(ProductPriceRepository::class)->reemplazar($stretch->id, [
        ['valores' => ['Presentación' => 'Manual', 'Cantidad' => '1-10'],  'precio' => 145, 'unidad' => null, 'minimo' => null, 'nota' => null, 'orden' => 0],
        ['valores' => ['Presentación' => 'Manual', 'Cantidad' => '11-30'], 'precio' => 135, 'unidad' => null, 'minimo' => null, 'nota' => null, 'orden' => 1],
        ['valores' => ['Presentación' => 'Manual', 'Cantidad' => '51+'],   'precio' => 120, 'unidad' => null, 'minimo' => null, 'nota' => null, 'orden' => 2],
    ]);

    foreach ([1 => 145.0, 10 => 145.0, 11 => 135.0, 51 => 120.0, 400 => 120.0] as $cantidad => $esperado) {
        $r = test()->postJson("/api/v1/productos/catalogo/{$stretch->sku}/precio", [
            'criterios' => ['Presentación' => 'Manual'],
            'cantidad'  => $cantidad,
        ])->assertOk();

        expect($r->json('estado'))->toBe('resuelto');
        expect((float) $r->json('precio.precio'))->toBe($esperado);
    }

    // Sin cantidad no elige tramo: la tiene que preguntar.
    $r = test()->postJson("/api/v1/productos/catalogo/{$stretch->sku}/precio", [
        'criterios' => ['Presentación' => 'Manual'],
    ])->assertOk();

    expect($r->json('estado'))->toBe('ambiguo');

});
