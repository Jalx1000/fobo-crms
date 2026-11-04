<?php

use Illuminate\Support\Facades\Validator;
use Webkul\Core\Contracts\Validations\Decimal;

/**
 * La regla que valida los atributos de tipo `price`.
 *
 * El bug que estos tests fijan: la regla admitía UN solo decimal
 * (`/^\d*(\.\d{1})?$/`), pero las columnas de precio son `decimal(12,4)`.
 * Eloquent devuelve "1.0000", el formulario lo pinta así, y al guardar el mismo
 * valor que acababa de leer no pasaba su propia validación.
 *
 * Resultado: **editar cualquier producto con precio era imposible**. Y no solo
 * productos - la regla la usan todos los atributos `price` del CRM, incluido
 * `lead_value` de las oportunidades.
 */
function pasaDecimal(mixed $valor): bool
{
    return Validator::make(['v' => $valor], ['v' => new Decimal])->passes();
}

it('acepta los 4 decimales que guarda la columna', function () {
    // decimal(12,4): esto es literalmente lo que Eloquent devuelve y el
    // formulario manda de vuelta sin que nadie lo toque.
    expect(pasaDecimal('1.0000'))->toBeTrue()
        ->and(pasaDecimal('0.0000'))->toBeTrue()
        ->and(pasaDecimal('1250.5000'))->toBeTrue();
});

it('sigue aceptando enteros y 1 a 3 decimales', function () {
    expect(pasaDecimal('1'))->toBeTrue()
        ->and(pasaDecimal('1.5'))->toBeTrue()
        ->and(pasaDecimal('1.50'))->toBeTrue()
        ->and(pasaDecimal('1.500'))->toBeTrue()
        ->and(pasaDecimal('0'))->toBeTrue();
});

it('rechaza más decimales de los que la columna puede guardar', function () {
    // Aceptarlos sería peor que rechazarlos: MySQL redondea en silencio y el
    // precio guardado deja de ser el que el usuario escribió.
    expect(pasaDecimal('1.00001'))->toBeFalse();
});

it('rechaza lo que no es un número', function () {
    expect(pasaDecimal('abc'))->toBeFalse()
        ->and(pasaDecimal('1.'))->toBeFalse()
        ->and(pasaDecimal('.'))->toBeFalse()
        ->and(pasaDecimal('1,50'))->toBeFalse()
        ->and(pasaDecimal('-5'))->toBeFalse();
});

it('editar y guardar un producto con precio ahora funciona', function () {
    /*
     * El test que reproduce el reporte: abrir la edición y guardar sin cambiar
     * nada. Antes redirigía de vuelta con "El price debe ser un número decimal"
     * porque el valor que el formulario devolvía era el que el propio CRM había
     * pintado.
     */
    test()->actingAs(getDefaultAdmin());

    $p = app(Webkul\Product\Repositories\ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Precio 4 decimales '.uniqid(),
        'sku'         => 'TEST-DEC-'.uniqid(),
        'price'       => 1250.5,
        'quantity'    => 1,
    ]);

    // Tal como sale de la base y como lo pinta el formulario.
    expect((string) $p->fresh()->price)->toBe('1250.5000');

    try {
        test()->put(route('admin.products.update', $p->id), [
            'entity_type' => 'products',
            'name'        => $p->name,
            'sku'         => $p->sku,
            'price'       => (string) $p->fresh()->price,
            'quantity'    => 1,
        ])->assertSessionHasNoErrors();
    } finally {
        // finally y no una línea suelta al final: si la aserción falla, el
        // producto de prueba igual se borra. Sin esto, cada corrida fallida
        // deja basura en la base compartida.
        $p->newQuery()->whereKey($p->id)->delete();
    }
});

it('lo mismo vale para el valor de una oportunidad', function () {
    // lead_value usa la misma regla: el bug afectaba a los leads igual que a
    // los productos, y ahí el campo es "Valor / comisión esperada".
    $atributo = Webkul\Attribute\Models\Attribute::where('entity_type', 'leads')
        ->where('code', 'lead_value')->first();

    expect($atributo?->type)->toBe('price')
        ->and(pasaDecimal('120000.0000'))->toBeTrue();
});
