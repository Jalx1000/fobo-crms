<?php

use Webkul\Product\Models\ProductMedia;
use Webkul\Product\Repositories\ProductRepository;

/**
 * El chip vacío del multiselect.
 *
 * Un producto sin categoría mostraba DOS chips: uno con la categoría y otro sin
 * texto, solo con la crucecita de quitar.
 *
 * Causa: `explode(',', '')` en PHP devuelve `['']` -un array con una cadena
 * vacía- y no un array vacío. Ese '' llegaba al componente como una opción
 * seleccionada más: se dibujaba como chip (getItemName('') no encuentra opción y
 * devuelve el id crudo, que es '') y viajaba en el submit.
 *
 * No era un problema de estilos: taparlo con CSS habría dejado el '' guardándose.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Multiselect '.uniqid(),
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

it('un producto sin categoría no manda ninguna opción seleccionada', function () {
    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    // El model-value del multiselect de categoría tiene que ser una lista vacía.
    // Antes decía [""] y eso era el chip fantasma.
    expect($html)->toContain('field.value || []')
        ->and($html)->not->toContain('field.value || [&quot;&quot;]')
        ->and($html)->not->toContain('field.value || [""]');
});

it('el componente Vue descarta las entradas vacías del array', function () {
    /*
     * Defensa en profundidad: aunque PHP mande la lista limpia, vee-validate
     * puede devolver [''] al quitar el último chip. El caso string ya filtraba;
     * el de array no.
     */
    $layout = file_get_contents(base_path(
        'packages/Webkul/Admin/src/Resources/views/components/layouts/index.blade.php'
    ));

    expect($layout)->toContain("filter(valor => valor !== '')")
        // La versión vieja mapeaba a String sin filtrar nada.
        ->and($layout)->not->toContain('return Array.isArray(this.modelValue) ? this.modelValue.map(String) : [];');
});

it('no quedan explode sin castear en los componentes de atributos', function () {
    // explode(',', null) emite deprecation en PHP 8.1+ y es el mismo origen del bug.
    foreach (['multiselect', 'checkbox'] as $componente) {
        $blade = file_get_contents(base_path(
            "packages/Webkul/Admin/src/Resources/views/components/attributes/edit/{$componente}.blade.php"
        ));

        expect($blade)->not->toContain("explode(',', \$selectedOption)");
    }
});
