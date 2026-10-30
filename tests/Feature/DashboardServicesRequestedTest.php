<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;
use Webkul\Product\Models\Product;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * El nuevo card "Servicios solicitados" muestra las métricas de los servicios
 * pedidos dentro del rango. El endpoint debe responder 200 con una lista
 * (statistics) y un total agregado.
 */
it('el endpoint services-requested responde 200 con statistics y total', function () {
    $response = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'services-requested']))
        ->assertStatus(200);

    $payload = $response->json('statistics');

    expect($payload)->toHaveKeys(['statistics', 'total']);
    expect($payload['statistics'])->toBeArray();
    expect($payload['total'])->toBeInt();

    // El total debe ser la suma de las cantidades de cada servicio listado.
    $sum = array_sum(array_column($payload['statistics'], 'total_qty_ordered'));
    expect($payload['total'])->toBe($sum);

    // Cada servicio debe exponer las claves que consume la torta (segmentos y
    // leyenda): id, name, total_qty_ordered y leads_count.
    foreach ($payload['statistics'] as $stat) {
        expect($stat)->toHaveKeys(['id', 'name', 'total_qty_ordered', 'leads_count']);
    }
});

/**
 * Crea un servicio pedido (product + lead_products) sobre un lead con la fecha
 * dada y devuelve el id del producto. Aislado por producto único para poder
 * medirlo sin interferencia de otros datos.
 *
 * Nota: cada test hace UN solo request. El helper del tablero (con las fechas
 * leídas en su constructor) queda memoizado en el objeto Route, así que un
 * segundo request dentro del mismo test reutilizaría las fechas del primero.
 */
function makeServiceLead(\Carbon\Carbon $when): int
{
    $product = Product::create([
        'name' => 'Servicio Rango '.uniqid(),
        'sku'  => 'SR-'.uniqid(),
    ]);

    $stage = DB::table('lead_pipeline_stages')->where('code', 'consultas')->first();

    $lead = Lead::create([
        'title'                  => 'Lead Servicio Rango '.uniqid(),
        'status'                 => 1,
        'lead_pipeline_id'       => $stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stage->id,
    ]);
    $lead->created_at = $when;
    $lead->save();

    DB::table('lead_products')->insert([
        'lead_id'    => $lead->id,
        'product_id' => $product->id,
        'quantity'   => 4,
        'price'      => 0,
        'amount'     => 0,
        'created_at' => $when,
        'updated_at' => $when,
    ]);

    return $product->id;
}

function serviceQty(int $productId, array $params): int
{
    $stats = test()->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', array_merge(['type' => 'services-requested'], $params)))
        ->assertStatus(200)
        ->json('statistics.statistics');

    return collect($stats)->firstWhere('id', $productId)['total_qty_ordered'] ?? 0;
}

it('incluye el servicio cuando el rango cubre la fecha del lead', function () {
    $productId = makeServiceLead(now());

    expect(serviceQty($productId, [
        'start' => now()->subDays(2)->format('Y-m-d'),
        'end'   => now()->format('Y-m-d'),
    ]))->toBe(4);
});

it('excluye el servicio cuando el rango no cubre la fecha del lead', function () {
    $productId = makeServiceLead(now());

    expect(serviceQty($productId, [
        'start' => now()->subDays(40)->format('Y-m-d'),
        'end'   => now()->subDays(30)->format('Y-m-d'),
    ]))->toBe(0);
});

/**
 * El card over-all debe exponer total_leads (fuente del indicador
 * "Total de Citas"), separado de total_consultas ("Total de Consultas",
 * que reemplazó a "Total de Pacientes").
 */
it('over-all expone total_leads y total_consultas por separado', function () {
    $stats = $this->actingAs(User::find(1), 'user')
        ->getJson(route('admin.dashboard.stats', ['type' => 'over-all']))
        ->assertStatus(200)
        ->json('statistics');

    expect($stats)->toHaveKeys(['total_leads', 'total_consultas']);
    expect($stats['total_leads'])->toHaveKey('current');
    expect($stats['total_consultas'])->toHaveKey('current');
});
