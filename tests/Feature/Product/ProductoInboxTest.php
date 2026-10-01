<?php

use Webkul\Product\Models\ProductMedia;
use Webkul\Product\Repositories\ProductMediaRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Envío de la ficha de un producto desde el inbox de WhatsApp.
 *
 * Lo que se afirma acá es la frontera entre módulos: que Admin pueda mandar
 * adjuntos por WhatsApp sin que Webkul/Whatsapp sepa que los productos existen.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Caja troquelada '.uniqid(),
        'sku'         => 'TEST-'.uniqid(),
        'price'       => 250,
    ]);
});

afterEach(function () {
    // Defensivo: si beforeEach falló a mitad (el repositorio inserta el
    // producto y recién después guarda los atributos), $this->producto puede
    // no existir — pero la fila sí. Sin esto, una corrida fallida deja
    // productos huérfanos en la base compartida.
    if (! isset($this->producto)) {
        return;
    }

    ProductMedia::where('product_id', $this->producto->id)->delete();

    $this->producto->newQuery()->whereKey($this->producto->id)->delete();
});

it('el buscador del inbox solo devuelve productos que tienen adjuntos', function () {
    // Sin adjuntos no hay nada que mandar: ofrecerlo en el selector solo hace
    // que el asesor lo elija y se encuentre con una lista vacía.
    $respuesta = test()->getJson(route('admin.products.inbox.buscar', ['q' => $this->producto->sku]));

    $respuesta->assertOk();

    expect(collect($respuesta->json('data'))->pluck('id'))->not->toContain($this->producto->id);

    app(ProductMediaRepository::class)->create([
        'product_id' => $this->producto->id,
        'tipo'       => 'enlace',
        'url'        => 'https://ejemplo.com/ficha',
    ]);

    $respuesta = test()->getJson(route('admin.products.inbox.buscar', ['q' => $this->producto->sku]));

    expect(collect($respuesta->json('data'))->pluck('id'))->toContain($this->producto->id);
});

it('el buscador expone el enlace de cada adjunto ya resuelto', function () {
    app(ProductMediaRepository::class)->create([
        'product_id' => $this->producto->id,
        'tipo'       => 'imagen',
        'path'       => 'productos/1/foto.jpg',
    ]);

    $respuesta = test()->getJson(route('admin.products.inbox.buscar', ['q' => $this->producto->sku]));

    $adjunto = collect($respuesta->json('data'))
        ->firstWhere('id', $this->producto->id)['adjuntos'][0];

    expect($adjunto['tipo'])->toBe('imagen')
        ->and($adjunto['enlace'])->toContain('/archivo');
});

it('rechaza un envío de más de 10 adjuntos', function () {
    // No es el límite de carga (20 por tanda): es cuántos aguanta el chat del
    // cliente sin volverse ilegible.
    test()->postJson(route('admin.products.inbox.enviar'), [
        'conversation_id' => 1,
        'product_id'      => $this->producto->id,
        'media_ids'       => range(1, 11),
    ])->assertStatus(422)->assertJsonValidationErrors('media_ids');
});

it('exige conversación, producto y adjuntos', function () {
    test()->postJson(route('admin.products.inbox.enviar'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['conversation_id', 'product_id', 'media_ids']);
});

it('no deja enviar adjuntos que no son del producto indicado', function () {
    $otro = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Otro '.uniqid(),
        'sku'         => 'TEST-'.uniqid(),
        'price'       => 1,
    ]);

    $ajeno = app(ProductMediaRepository::class)->create([
        'product_id' => $otro->id,
        'tipo'       => 'enlace',
        'url'        => 'https://ejemplo.com/ajeno',
    ]);

    // La conversación 1 puede no existir: lo que importa es que el filtro por
    // producto corte antes de mandar el adjunto de otro.
    $respuesta = test()->postJson(route('admin.products.inbox.enviar'), [
        'conversation_id' => 1,
        'product_id'      => $this->producto->id,
        'media_ids'       => [$ajeno->id],
    ]);

    expect($respuesta->status())->toBeIn([404, 422]);

    ProductMedia::where('product_id', $otro->id)->delete();

    $otro->delete();
});

it('el módulo de WhatsApp no gana acoplamiento nuevo con este cambio', function () {
    /*
    | La regla que sostiene la arquitectura de este cambio: Webkul/Whatsapp lo
    | comparte el otro tenant (c21_laravel), así que la galería y el envío de
    | fichas viven en Admin y no ahí.
    |
    | Ojo con lo que NO afirma este test: Whatsapp ya importaba ProductProxy
    | antes de este trabajo (ChatController, para la pestaña «Productos» y las
    | variables {{producto}}/{{precio}} de las respuestas rápidas). Ese
    | acoplamiento es previo y queda como está; lo que se protege acá es que no
    | crezca con las clases nuevas.
    */
    $ruta = base_path('packages/Webkul/Whatsapp/src');

    $hits = [];

    $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ruta));

    foreach ($iterador as $archivo) {
        if ($archivo->isDir() || $archivo->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/ProductMedia|ProductoInbox|Support\\\\Caracteristicas/', file_get_contents($archivo->getPathname()))) {
            $hits[] = str_replace($ruta.'/', '', $archivo->getPathname());
        }
    }

    expect($hits)->toBe([]);
});

it('deja constancia del acoplamiento previo, para que no pase desapercibido', function () {
    // Si algún día alguien limpia ChatController, este test falla y obliga a
    // revisar el comentario de arriba en vez de dejarlo mintiendo.
    $chat = file_get_contents(base_path('packages/Webkul/Whatsapp/src/Http/Controllers/ChatController.php'));

    expect($chat)->toContain('Webkul\\Product\\Models\\ProductProxy');
});
