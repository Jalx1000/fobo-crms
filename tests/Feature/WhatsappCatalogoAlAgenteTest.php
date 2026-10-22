<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Distribuidor\Services\Catalogo;
use Webkul\Product\Support\ProductosActivos;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * El agente recibe en cada mensaje los productos y precios de cada zona, y los
 * productos inactivos no aparecen en ningún lado fuera del módulo de Productos.
 */
uses(DatabaseTransactions::class);

beforeEach(fn () => Queue::fake());

function catalogoEvento(?int $ciudadId = null): array
{
    $conv = ConversationProxy::modelClass()::create([
        'gateway' => 'cloud_api', 'wa_phone' => '+54938'.random_int(10000000, 99999999),
        'wa_name' => 'Cliente catálogo', 'ai_enabled' => true, 'last_message_at' => now(),
    ]);

    if ($ciudadId) {
        $conv->forceFill(['handoff_city_id' => $ciudadId])->save();
    }

    $mensaje = MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id, 'direction' => 'inbound', 'sender' => 'contact',
        'type' => 'text', 'body' => 'Palpalá', 'status' => 'delivered',
    ]);

    return app(AgentPayload::class)->event($mensaje->fresh('conversation'))['catalogo'];
}

function catalogoInactivar(int $productId): void
{
    $atributo = DB::table('attributes')->where('code', ProductosActivos::ATRIBUTO)->where('entity_type', 'products')->value('id')
        ?: test()->markTestSkipped('No existe el atributo Estado de productos.');

    DB::table('attribute_values')->updateOrInsert(
        ['attribute_id' => $atributo, 'entity_type' => 'products', 'entity_id' => $productId],
        ['boolean_value' => 0],
    );
}

function catalogoPalpala(): int
{
    return (int) DB::table('lead_pipelines')->where('name', 'like', 'Palpal%')->value('id')
        ?: test()->markTestSkipped('No existe la ciudad Palpalá.');
}

it('le manda al agente los productos y precios de cada zona con cobertura', function () {
    $catalogo = catalogoEvento();

    expect($catalogo['zonas_con_cobertura'])->toContain('Palpalá');

    $palpala = collect($catalogo['por_zona']['Palpalá'])->keyBy('product_id');
    $sucursal = collect(app(Catalogo::class)->sucursales())->firstWhere('ciudad_id', catalogoPalpala());

    // Lo mismo que vende el Punto en esa sucursal, con el mismo precio.
    foreach (app(Catalogo::class)->productosDe($sucursal['id']) as $producto) {
        expect($palpala[$producto['id']]['precio'])->toBe($producto['price']);
    }
});

it('dice la zona del cliente cuando el CRM la sabe', function () {
    expect(catalogoEvento(catalogoPalpala())['zona_del_cliente'])->toBe('Palpalá')
        ->and(catalogoEvento()['zona_del_cliente'])->toBeNull();
});

it('un producto inactivo no le llega al agente ni al Punto', function () {
    $catalogo = catalogoEvento();
    $producto = $catalogo['por_zona']['Palpalá'][0]['product_id'] ?? test()->markTestSkipped('Palpalá no tiene productos.');

    catalogoInactivar($producto);

    $ids = collect(catalogoEvento()['por_zona'])->flatten(1)->pluck('product_id');
    $sucursal = collect(app(Catalogo::class)->sucursales())->firstWhere('ciudad_id', catalogoPalpala());

    expect($ids)->not->toContain($producto)
        ->and(collect(app(Catalogo::class)->productosDe($sucursal['id']))->pluck('id'))->not->toContain($producto);
});

it('el buscador de productos de pedidos y cotizaciones no ofrece inactivos', function () {
    $producto = (int) DB::table('products')->value('id') ?: test()->markTestSkipped('No hay productos.');
    $nombre = (string) DB::table('products')->where('id', $producto)->value('name');

    catalogoInactivar($producto);

    $ids = collect(test()->actingAs(getDefaultAdmin(), 'user')
        ->getJson(route('admin.products.search', ['query' => $nombre]))
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->not->toContain($producto);
});

it('la API de productos no lista inactivos', function () {
    $producto = (int) DB::table('products')->value('id') ?: test()->markTestSkipped('No hay productos.');

    catalogoInactivar($producto);

    test()->actingAs(getDefaultAdmin(), 'sanctum');

    $ids = collect(test()->getJson('/api/v1/products?limit=200')->assertOk()->json('data'))->pluck('id');

    expect($ids)->not->toContain($producto);
});

it('le manda al agente cada ciudad con los ids de sus etapas, sin «Sin ciudad»', function () {
    $conv = ConversationProxy::modelClass()::create([
        'gateway' => 'cloud_api', 'wa_phone' => '+54938'.random_int(10000000, 99999999),
        'wa_name' => 'Cliente etapas', 'ai_enabled' => true, 'last_message_at' => now(),
    ]);
    $mensaje = MessageProxy::modelClass()::create([
        'conversation_id' => $conv->id, 'direction' => 'inbound', 'sender' => 'contact',
        'type' => 'text', 'body' => 'Hola', 'status' => 'delivered',
    ]);

    $ciudades = collect(app(AgentPayload::class)->event($mensaje->fresh('conversation'))['asignacion']['ciudades'])->keyBy('ciudad');
    $palpala = catalogoPalpala();

    expect($ciudades->has('Sin ciudad'))->toBeFalse()
        ->and($ciudades['Palpalá']['pipeline_id'])->toBe($palpala)
        ->and($ciudades['Palpalá']['etapas']['pedidos-confirmado'])
        ->toBe((int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $palpala)->where('code', 'pedidos-confirmado')->value('id'));
});
