<?php

use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductoResponsable;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\User\Models\User;
use Webkul\Warehouse\Models\Location;
use Webkul\Warehouse\Models\Warehouse;

/**
 * Sucursales (almacenes) y responsables por ciudad y producto.
 *
 * La sucursal responde «¿dónde lo retiro?» y el responsable «¿con quién hablo?»:
 * las dos preguntas que el agente no puede contestar solo con el catálogo.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->producto = app(ProductRepository::class)->create([
        'entity_type' => 'products',
        'name'        => 'Sucursal test '.uniqid(),
        'sku'         => 'TEST-SUC-'.uniqid(),
        'price'       => 0,
        'quantity'    => 0,
    ]);
});

afterEach(function () {
    if (isset($this->producto)) {
        ProductoResponsable::where('product_id', $this->producto->id)->delete();
        DB::table('products')->where('id', $this->producto->id)->delete();
    }
});

function formSucursal($p, array $extra = []): array
{
    return array_merge([
        'entity_type'              => 'products',
        'name'                     => $p->name,
        'sku'                      => $p->sku,
        'price'                    => 0,
        'quantity'                 => 0,
        'precios_enviados'         => 1,
        'caracteristicas_enviadas' => 1,
        'sucursal_enviada'         => 1,
    ], $extra);
}

/**
 * Un solo almacén, Santa Cruz, con una ubicación por planta (pedido del
 * 13/10). La ubicación se llama como la línea que fabrica.
 */
function plantaDe(string $linea): ?Location
{
    return Location::with('warehouse')->where('name', $linea)->first();
}

it('hay un solo almacén, Santa Cruz', function () {
    expect(Warehouse::pluck('name')->all())->toBe(['Santa Cruz']);
});

it('las tres plantas están cargadas con su línea y su enlace de Maps', function () {
    foreach ([
        'División de Embalajes' => '5sCggqMUD1vZ8Evq8',
        'Tapas'                 => 'WCjyTGf4VAqjrQ4V7',
        'Envases Flexibles'     => '7w5END7cgWJVAiYR7',
    ] as $linea => $maps) {
        $s = plantaDe($linea);

        expect($s)->not->toBeNull("falta la planta de {$linea}");
        expect($s->maps_url)->toContain($maps)
            ->and($s->warehouse->name)->toBe('Santa Cruz');
    }
});

it('tapas se despacha desde la planta de tapas', function () {
    // Es el único producto con planta asignada a propósito: es el único que se
    // retira en planta.
    expect(Product::where('sku', 'CM_00004')->first()->ubicacion?->name)->toBe('Tapas');
});

it('guarda la sucursal y los responsables desde el formulario', function () {
    $sucursal = plantaDe('División de Embalajes');
    $asesores = User::where('status', 1)->limit(2)->get();

    test()->put(route('admin.products.update', $this->producto->id), formSucursal($this->producto, [
        'warehouse_location_id' => $sucursal->id,
        'responsables'          => [
            'Santa Cruz' => [$asesores[0]->id],
            'La Paz'     => [$asesores[1]->id],
            'Cochabamba' => [],   // sin asignar
        ],
    ]))->assertRedirect();

    $this->producto->refresh()->load('responsables');

    expect($this->producto->warehouse_location_id)->toBe($sucursal->id)
        ->and($this->producto->warehouse_id)->toBe($sucursal->warehouse_id);
    expect($this->producto->responsables)->toHaveCount(2);
    expect($this->producto->responsableEn('Santa Cruz')?->id)->toBe($asesores[0]->id);
    expect($this->producto->responsableEn('La Paz')?->id)->toBe($asesores[1]->id);

    // «Sin asignar» no guarda una fila con usuario nulo.
    expect($this->producto->responsableEn('Cochabamba'))->toBeNull();
});

it('el responsable se resuelve con la misma tolerancia que el precio', function () {
    $asesor = User::where('status', 1)->first();

    ProductoResponsable::create([
        'product_id' => $this->producto->id,
        'ciudad'     => 'Santa Cruz',
        'user_id'    => $asesor->id,
    ]);

    $this->producto->load('responsables');

    // El agente manda lo que entendió de la charla: un acento no puede dejar
    // sin responsable a un producto que sí lo tiene.
    expect($this->producto->responsableEn('santa cruz')?->id)->toBe($asesor->id);
    expect($this->producto->responsableEn('SANTA  CRUZ')?->id)->toBe($asesor->id);
    expect($this->producto->responsableEn('Tarija'))->toBeNull();
    expect($this->producto->responsableEn(null))->toBeNull();
});

it('un guardado parcial no borra la sucursal ni los responsables', function () {
    $sucursal = plantaDe('Envases Flexibles');
    $asesor = User::where('status', 1)->first();

    $this->producto->forceFill(['warehouse_id' => $sucursal->warehouse_id, 'warehouse_location_id' => $sucursal->id])->save();
    ProductoResponsable::create(['product_id' => $this->producto->id, 'ciudad' => 'La Paz', 'user_id' => $asesor->id]);

    // El editor inline de atributos postea sin estos campos.
    test()->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->putJson(route('admin.products.update', $this->producto->id), [
            'entity_type' => 'products', 'name' => $this->producto->name, 'sku' => $this->producto->sku, 'price' => 5, 'quantity' => 0,
        ])->assertOk();

    expect($this->producto->refresh()->warehouse_location_id)->toBe($sucursal->id);
    expect(ProductoResponsable::where('product_id', $this->producto->id)->count())->toBe(1);
});

it('borrar el producto se lleva sus responsables', function () {
    $asesor = User::where('status', 1)->first();

    ProductoResponsable::create(['product_id' => $this->producto->id, 'ciudad' => 'La Paz', 'user_id' => $asesor->id]);

    $id = $this->producto->id;

    DB::table('products')->where('id', $id)->delete();
    unset($this->producto);

    expect(ProductoResponsable::where('product_id', $id)->count())->toBe(0);
});

it('el agente recibe la sucursal solo cuando se retira en planta', function () {
    actingAsSanctumAuthenticatedAdmin();

    // Tapas: se retira en planta → va la dirección.
    $r = test()->postJson('/api/v1/productos/catalogo/CM_00004/precio', [
        'criterios' => ['Impresión' => 'Con impresión'], 'cantidad' => 30,
    ])->assertOk();

    expect($r->json('entrega.sucursal.nombre'))->toBe('Santa Cruz · Tapas');
    expect($r->json('entrega.sucursal.maps'))->toContain('maps.app.goo.gl');

    // Hules a domicilio: mandar la dirección de la fábrica confundiría.
    expect(test()->postJson('/api/v1/productos/catalogo/CM_00003/precio', [
        'criterios' => ['Color' => 'Negro'], 'cantidad' => 5,
    ])->json('entrega'))->not->toHaveKey('sucursal');
});

it('el formulario del producto ofrece las sucursales, las tres ciudades y Bolivia', function () {
    $html = test()->get(route('admin.products.edit', $this->producto->id))->assertOk()->getContent();

    expect($html)->toContain('name="warehouse_location_id"')
        ->and($html)->toContain('Santa Cruz · Tapas');

    foreach (array_merge(config('cotizacion.ciudades'), [config('cotizacion.cobertura_nacional')]) as $ciudad) {
        expect($html)->toContain("name=\"responsables[{$ciudad}][]\"");
    }
});

it('el CRM acepta un contacto sin correo ni dirección', function () {
    /*
     * Pedido del 14/09: el agente no debe exigir correo ni dirección. Se
     * verifica del lado del CRM porque una validación acá anularía el cambio
     * del prompt: el agente los pediría igual, pero recién al fallar.
     */
    actingAsSanctumAuthenticatedAdmin();

    $r = test()->postJson('/api/v1/contacts/persons', [
        'entity_type'     => 'persons',
        'name'            => 'Sin correo '.uniqid(),
        'contact_numbers' => [['value' => '59177000123', 'label' => 'work']],
        'nit'             => '1234567019',
    ])->assertOk();

    $id = $r->json('data.id');

    expect($id)->not->toBeNull();

    // Y la cotización tampoco los exige.
    $reglas = (new ReflectionMethod(\Webkul\Product\Http\Controllers\Api\AgenteController::class, 'cotizacion'))
        ->getFileName();

    $fuente = file_get_contents($reglas);

    expect($fuente)->not->toContain("'correo'")
        ->and($fuente)->not->toContain("'direccion' => 'required");

    DB::table('attribute_values')->where('entity_type', 'persons')->where('entity_id', $id)->delete();
    DB::table('persons')->where('id', $id)->delete();
});
