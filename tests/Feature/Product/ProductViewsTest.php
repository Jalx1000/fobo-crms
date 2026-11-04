<?php

use Webkul\Product\Models\ProductMedia;
use Webkul\Product\Repositories\ProductMediaRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Que las vistas rendericen de verdad.
 *
 * Los tests de la capa de datos no ven un Blade roto ni un token que se lee de
 * un <meta> inexistente. Estos sí: piden la página y afirman sobre el HTML.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Vistas '.uniqid(),
        'sku'         => 'TEST-'.uniqid(),
        'price'       => 10,
    ]);
});

afterEach(function () {
    if (! isset($this->producto)) {
        return;
    }

    ProductMedia::where('product_id', $this->producto->id)->delete();

    $this->producto->newQuery()->whereKey($this->producto->id)->delete();
});

it('el alta renderiza el dropzone y acepta multipart', function () {
    $html = test()->get(route('admin.products.create'))->assertOk()->getContent();

    expect($html)->toContain('data-dropzone')
        ->and($html)->toContain('name="imagenes[]"')
        // Sin enctype las imágenes no llegan nunca al controlador.
        ->and($html)->toContain('multipart/form-data');
});

it('la edición renderiza galería y dropzone que sube al soltar', function () {
    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    expect($html)->toContain('data-dropzone')
        // data-subir-a es lo que hace que soltar suba directo en vez de esperar
        // al botón.
        ->and($html)->toContain('data-subir-a')
        ->and($html)->toContain('name="archivos[]"');
});

it('el token CSRF va embebido y no se lee de un meta inexistente', function () {
    /*
     * El bug que esto previene: este admin NO tiene <meta name="csrf-token">.
     * Leer el token de ahí devuelve null y toda petición muere con 419 -
     * portada, borrado y reordenamiento dejarían de funcionar en silencio.
     */
    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    expect($html)->not->toContain("querySelector('meta[name=\"csrf-token\"]')")
        ->and($html)->toContain(csrf_token());
});

it('los adjuntos aparecen en la galería con sus controles', function () {
    app(ProductMediaRepository::class)->create([
        'product_id' => $this->producto->id,
        'tipo'       => 'imagen',
        'path'       => 'productos/1/foto.jpg',
    ]);

    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    expect($html)->toContain('data-portada')
        ->and($html)->toContain('data-borrar')
        // draggable es lo que habilita reordenar arrastrando.
        ->and($html)->toContain('draggable="true"');
});

it('las características muestran los sugeridos de la categoría', function () {
    config()->set('caracteristicas.por_categoria.division-de-embalajes', [
        'gramaje' => ['label' => 'Gramaje de prueba'],
    ]);

    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    expect($html)->toContain('caracteristicas[clave][]')
        ->and($html)->toContain('caracteristicas[valor][]')
        ->and($html)->toContain('caracteristicas-sugeridas');
});

it('los scripts llegan enteros: ninguna directiva Blade se cuela en el JS', function () {
    /*
     * El bug que esto previene, y que ya ocurrió:
     *
     * Un comentario de JavaScript decía «...corre después del stack de scripts»,
     * pero escrito con la directiva Blade de verdad. Blade no distingue
     * comentarios de código: ejecutó la directiva, volcó el stack entero en
     * medio del comentario y cerró ahí el bloque <script>. Todo el JS del
     * dropzone quedó fuera de cualquier <script> y nunca se ejecutó: arrastrar
     * archivos no hacía nada, sin un solo error en consola, porque el navegador
     * veía texto suelto y no JavaScript roto.
     *
     * La afirmación correcta no es «no hay </script> en el medio» -el corte pasa
     * ANTES del código- sino «este código está dentro de un <script>»: el último
     * <script de apertura tiene que venir después del último </script> de cierre.
     */
    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    foreach ([
        'dropzone (arrastrar para subir)' => 'const zonaDe',
        'galería (portada y orden)'       => 'const urlPortada',
        'características'                 => 'data-quitar-caracteristica]',
        'precios por variante'            => 'const ejesCont',
    ] as $bloque => $marca) {
        $pos = strpos($html, $marca);

        expect($pos)->not->toBeFalse("no se encontró el script de {$bloque}");

        $apertura = strrpos(substr($html, 0, $pos), '<script');
        $cierre = strrpos(substr($html, 0, $pos), '</script>');

        expect($apertura)->toBeGreaterThan(
            $cierre === false ? -1 : $cierre,
            "el script de {$bloque} quedó FUERA de su bloque <script>: una directiva Blade lo cortó"
        );
    }
});

it('el dropzone y la galería registran los eventos de arrastre', function () {
    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    // Sin estos tres el arrastre no hace nada: dragover necesita preventDefault
    // para que el navegador admita soltar, y drop es donde se sube.
    expect($html)->toContain("'dragenter'")
        ->and($html)->toContain("'dragover'")
        ->and($html)->toContain("addEventListener('drop'");
});
