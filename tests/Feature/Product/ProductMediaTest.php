<?php

use Webkul\Product\Models\ProductMedia;
use Webkul\Product\Repositories\ProductMediaRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Adjuntos de un producto.
 *
 * Estos SÍ tocan la base. Corren contra la misma base de desarrollo, así que
 * cada uno limpia lo que crea: no hay RefreshDatabase porque truncar esta base
 * borraría los productos y leads reales.
 */
beforeEach(function () {
    // entity_type lo inyecta el controlador en su constructor; acá se pasa a
    // mano porque el repositorio lo necesita para guardar atributos custom.
    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Producto de prueba '.uniqid(),
        'sku'         => 'TEST-'.uniqid(),
        'price'       => 100,
    ]);
});

afterEach(function () {
    // Defensivo: si beforeEach falló a mitad (el repositorio inserta el
    // producto y recién después guarda los atributos), $this->producto puede
    // no existir - pero la fila sí. Sin esto, una corrida fallida deja
    // productos huérfanos en la base compartida.
    if (! isset($this->producto)) {
        return;
    }

    ProductMedia::where('product_id', $this->producto->id)->delete();

    $this->producto->newQuery()->whereKey($this->producto->id)->delete();
});

it('guarda y relee las características como array', function () {
    $this->producto->update(['caracteristicas' => ['gramaje' => '300 g/m²']]);

    expect($this->producto->fresh()->caracteristicas)->toBe(['gramaje' => '300 g/m²']);
});

it('continúa el orden en vez de reiniciarlo al subir una segunda tanda', function () {
    // Subir en tandas no debe reordenar lo que el asesor ya acomodó.
    $repo = app(ProductMediaRepository::class);

    foreach ([1, 2, 3] as $i) {
        $repo->create([
            'product_id' => $this->producto->id,
            'tipo'       => 'imagen',
            'path'       => "productos/{$this->producto->id}/f{$i}.jpg",
            'orden'      => $i,
        ]);
    }

    expect($repo->ultimoOrden($this->producto->id, 'imagen'))->toBe(3);
});

it('lleva el orden por tipo, no global', function () {
    $repo = app(ProductMediaRepository::class);

    $repo->create(['product_id' => $this->producto->id, 'tipo' => 'imagen', 'path' => 'a.jpg', 'orden' => 5]);

    // Un documento nuevo arranca en 0 aunque ya haya imágenes en 5: cada
    // galería se ordena sola.
    expect($repo->ultimoOrden($this->producto->id, 'documento'))->toBe(0);
});

it('marcar una portada desmarca la anterior', function () {
    $repo = app(ProductMediaRepository::class);

    $primera = $repo->create([
        'product_id' => $this->producto->id,
        'tipo'       => 'imagen',
        'path'       => 'a.jpg',
        'es_portada' => true,
    ]);

    $segunda = $repo->create([
        'product_id' => $this->producto->id,
        'tipo'       => 'imagen',
        'path'       => 'b.jpg',
    ]);

    $repo->marcarPortada($this->producto->id, $segunda->id);

    expect($primera->fresh()->es_portada)->toBeFalse()
        ->and($segunda->fresh()->es_portada)->toBeTrue();
});

it('ordena la galería con la portada primero', function () {
    $repo = app(ProductMediaRepository::class);

    $repo->create(['product_id' => $this->producto->id, 'tipo' => 'imagen', 'path' => 'a.jpg', 'orden' => 1]);
    $repo->create(['product_id' => $this->producto->id, 'tipo' => 'imagen', 'path' => 'b.jpg', 'orden' => 2]);
    $portada = $repo->create(['product_id' => $this->producto->id, 'tipo' => 'imagen', 'path' => 'c.jpg', 'orden' => 3, 'es_portada' => true]);

    // Es el orden en que se envían por WhatsApp: mandar la portada tercera haría
    // que el cliente vea primero un detalle en vez de la foto principal.
    expect($this->producto->fresh()->medias->first()->id)->toBe($portada->id);
});

it('un enlace no es un archivo y no apunta a una ruta del disco', function () {
    $repo = app(ProductMediaRepository::class);

    $enlace = $repo->create([
        'product_id' => $this->producto->id,
        'tipo'       => 'enlace',
        'url'        => 'https://ejemplo.com/ficha',
    ]);

    expect($enlace->esArchivo())->toBeFalse()
        ->and($enlace->enlace)->toBe('https://ejemplo.com/ficha');
});

it('un archivo se sirve por la ruta autenticada, no por una URL de storage', function () {
    $repo = app(ProductMediaRepository::class);

    $imagen = $repo->create([
        'product_id' => $this->producto->id,
        'tipo'       => 'imagen',
        'path'       => 'productos/1/foto.jpg',
    ]);

    // La galería no debe ser visible para cualquiera que adivine el path.
    expect($imagen->esArchivo())->toBeTrue()
        ->and($imagen->enlace)->toContain('/medias/'.$imagen->id.'/archivo')
        ->and($imagen->enlace)->not->toContain('storage/');
});

it('borrar el producto borra sus adjuntos en cascada', function () {
    app(ProductMediaRepository::class)->create([
        'product_id' => $this->producto->id,
        'tipo'       => 'imagen',
        'path'       => 'a.jpg',
    ]);

    $id = $this->producto->id;

    $this->producto->delete();

    expect(ProductMedia::where('product_id', $id)->count())->toBe(0);

    // afterEach vuelve a borrar; que ya no exista no debe romper.
    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products', 'name' => 'recreado', 'sku' => 'TEST-'.uniqid(), 'price' => 1,
    ]);
});

it('guarda las imágenes soltadas en el alta y marca la primera como portada', function () {
    // El alta no puede subir por AJAX porque todavía no hay producto: las
    // imágenes viajan con el formulario y las guarda el controlador.
    Storage::fake('local');

    test()->actingAs(getDefaultAdmin());

    $sku = 'TEST-'.uniqid();

    test()->post(route('admin.products.store'), [
        'entity_type' => 'products',
        'name'        => 'Con fotos '.uniqid(),
        'sku'         => $sku,
        'price'       => 10,
        'quantity'    => 1,
        'imagenes'    => [
            \Illuminate\Http\UploadedFile::fake()->image('a.jpg'),
            \Illuminate\Http\UploadedFile::fake()->image('b.jpg'),
        ],
    ]);

    $creado = \Webkul\Product\Models\Product::where('sku', $sku)->first();

    expect($creado)->not->toBeNull();

    $medias = ProductMedia::where('product_id', $creado->id)->orderBy('orden')->get();

    expect($medias)->toHaveCount(2)
        ->and($medias[0]->es_portada)->toBeTrue()
        ->and($medias[1]->es_portada)->toBeFalse()
        ->and($medias[0]->tipo)->toBe('imagen');

    ProductMedia::where('product_id', $creado->id)->delete();

    $creado->newQuery()->whereKey($creado->id)->delete();
});

it('crea el producto igual si una imagen del alta es inválida', function () {
    // Es peor perder la carga entera por una imagen de más que guardar el
    // producto sin ella.
    Storage::fake('local');

    test()->actingAs(getDefaultAdmin());

    $sku = 'TEST-'.uniqid();

    test()->post(route('admin.products.store'), [
        'entity_type' => 'products',
        'name'        => 'Con basura '.uniqid(),
        'sku'         => $sku,
        'price'       => 10,
        'quantity'    => 1,
        'imagenes'    => [\Illuminate\Http\UploadedFile::fake()->create('virus.exe', 10)],
    ]);

    $creado = \Webkul\Product\Models\Product::where('sku', $sku)->first();

    expect($creado)->not->toBeNull()
        ->and(ProductMedia::where('product_id', $creado->id)->count())->toBe(0);

    $creado->newQuery()->whereKey($creado->id)->delete();
});
