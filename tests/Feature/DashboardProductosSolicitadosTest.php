<?php

use Illuminate\Support\Facades\DB;

/*
 * Las tortas del tablero cuadran con sus tarjetas. "Total productos solicitados"
 * y "Productos más solicitados" salen de la misma query: la torta lista TODOS
 * los productos y su total es el de la tarjeta.
 * Antes la torta se cortaba en 5 y no cuadraban (106 vs 88 en octubre 2026).
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción,
 * en una fecha pasada sin datos reales (el tablero no acepta un fin futuro).
 */
beforeEach(fn () => DB::beginTransaction());

afterEach(fn () => DB::rollBack());

const DPS_DIA = '2021-03-10';

function dpsStage(int $pipelineId, string $code): int
{
    return (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->get(['id', 'code'])
        ->first(fn ($s) => ltrim($s->code, '-') === $code)
        ->id;
}

/** Un pedido con sus productos: [product_id => cantidad]. */
function dpsLead(int $stageId, array $items): int
{
    $stage = DB::table('lead_pipeline_stages')->find($stageId);

    $leadId = (int) DB::table('leads')->insertGetId([
        'title'                  => 'Pedido de prueba tablero',
        'lead_value'             => 0,
        'status'                 => 1,
        'lead_pipeline_id'       => $stage->lead_pipeline_id,
        'lead_pipeline_stage_id' => $stageId,
        'created_at'             => DPS_DIA.' 10:00:00',
        'updated_at'             => DPS_DIA.' 10:00:00',
    ]);

    foreach ($items as $productId => $qty) {
        DB::table('lead_products')->insert([
            'lead_id'    => $leadId,
            'product_id' => $productId,
            'quantity'   => $qty,
            'price'      => 10,
            'amount'     => 10 * $qty,
            'created_at' => DPS_DIA.' 10:00:00',
            'updated_at' => DPS_DIA.' 10:00:00',
        ]);
    }

    return $leadId;
}

function dpsStats(string $type): array
{
    return test()->getJson(route('admin.dashboard.stats', [
        'type'        => $type,
        'start'       => DPS_DIA,
        'end'         => DPS_DIA,
        'pipeline_id' => 'all',
    ]))->assertOk()->json('statistics');
}

it('lista todos los productos de todas las etapas y su total es el de la tarjeta', function () {
    test()->actingAs(getDefaultAdmin());

    $pipelineId = (int) DB::table('lead_pipelines')->where('name', 'Cochabamba')->value('id');
    $productos = DB::table('products')->orderBy('id')->limit(7)->pluck('id')->all();

    // 7 productos (más que los 5 de antes) repartidos en etapas abiertas y cerradas.
    dpsLead(dpsStage($pipelineId, 'no-atendido'), [$productos[0] => 5, $productos[1] => 1]);
    dpsLead(dpsStage($pipelineId, 'cliente-confirmado'), [$productos[0] => 2, $productos[2] => 1, $productos[3] => 1]);
    dpsLead(dpsStage($pipelineId, 'cliente-sin-inters'), [$productos[4] => 1, $productos[5] => 1]);
    dpsLead(dpsStage($pipelineId, 'pedidos-cancelados'), [$productos[6] => 3]);

    $torta = dpsStats('quantity-products');
    $tarjeta = dpsStats('over-all')['total_services']['current'];

    expect($torta['products'])->toHaveCount(7)
        ->and(collect($torta['products'])->sum('total_qty_ordered'))->toBe(15)
        ->and($torta['total_qty'])->toBe(15)
        ->and($tarjeta)->toBe(15)
        ->and($torta['total_leads'])->toBe(4)
        ->and($torta['products'][0])->toMatchArray(['id' => $productos[0], 'total_qty_ordered' => 7, 'total_leads' => 2]);
});

it('los pedidos descartados no cuentan ni en la torta ni en la tarjeta', function () {
    test()->actingAs(getDefaultAdmin());

    $pipelineId = (int) DB::table('lead_pipelines')->where('name', 'Cochabamba')->value('id');
    $producto = (int) DB::table('products')->orderBy('id')->value('id');

    $descartado = (int) DB::table('lead_pipeline_stages')->insertGetId([
        'code'             => 'descartado',
        'name'             => 'Descartado',
        'probability'      => 0,
        'sort_order'       => 99,
        'lead_pipeline_id' => $pipelineId,
    ]);

    dpsLead(dpsStage($pipelineId, 'cliente-confirmado'), [$producto => 2]);
    dpsLead($descartado, [$producto => 2]);

    $torta = dpsStats('quantity-products');

    expect($torta['total_qty'])->toBe(2)
        ->and($torta['total_leads'])->toBe(1)
        ->and($torta['products'][0]['total_qty_ordered'])->toBe(2)
        ->and(dpsStats('over-all')['total_services']['current'])->toBe(2);
});

it('la torta de vendidos lista sólo entregados y cuadra con su tarjeta', function () {
    test()->actingAs(getDefaultAdmin());

    $pipelineId = (int) DB::table('lead_pipelines')->where('name', 'Cochabamba')->value('id');
    $productos = DB::table('products')->orderBy('id')->limit(7)->pluck('id')->all();
    $entregado = dpsStage($pipelineId, 'pedidos-entregados');

    // 6 productos entregados (más que los 5 de antes) y uno que sólo está confirmado.
    dpsLead($entregado, [$productos[0] => 3, $productos[1] => 1, $productos[2] => 1]);
    dpsLead($entregado, [$productos[3] => 1, $productos[4] => 2, $productos[5] => 1]);
    dpsLead(dpsStage($pipelineId, 'cliente-confirmado'), [$productos[6] => 4]);

    $torta = dpsStats('quantity-products-sold');

    expect($torta['products'])->toHaveCount(6)
        ->and($torta['total_qty'])->toBe(9)
        ->and(collect($torta['products'])->sum('total_qty_ordered'))->toBe(9)
        ->and($torta['total_leads'])->toBe(2)
        ->and(dpsStats('over-all')['total_products_sold']['current'])->toBe(9);
});

it('las tres tortas muestran la tabla en lugar de la lista de etiquetas', function () {
    test()->actingAs(getDefaultAdmin());

    $html = test()->get(route('admin.dashboard.index'))->assertOk()->getContent();

    expect(substr_count($html, 'v-for="(row, index) in products"'))->toBe(2)
        ->and($html)->toContain('v-for="(row, index) in rows"')
        ->and($html)->toContain('v-text="row.name ?? \'Producto eliminado\'"')
        ->and($html)->not->toContain('* 100 /')
        ->and($html)->not->toContain('stat.total_qty_ordered');
});

it('no atendidos por ciudad suma lo mismo que "No atendido" en la tarjeta por etapa', function () {
    test()->actingAs(getDefaultAdmin());

    $cocha = (int) DB::table('lead_pipelines')->where('name', 'Cochabamba')->value('id');
    $lapaz = (int) DB::table('lead_pipelines')->where('name', 'La Paz')->value('id');
    $admin = (int) DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.name', 'Administrador')->value('users.id');

    dpsLead(dpsStage($cocha, 'no-atendido'), []);
    dpsLead(dpsStage($lapaz, 'no-atendido'), []);

    // Asignado a un administrador: la torta lo dejaba afuera y la tarjeta no.
    $delAdmin = dpsLead(dpsStage($lapaz, 'no-atendido'), []);
    DB::table('leads')->where('id', $delAdmin)->update(['user_id' => $admin]);

    $torta = dpsStats('leads-no-atendidos-por-ciudad');
    $tarjeta = collect(dpsStats('open-leads-by-states-fixed'))->firstWhere('name', 'No atendido');

    expect(array_combine($torta['labels'], $torta['data']))->toBe(['La Paz' => 2, 'Cochabamba' => 1])
        ->and(array_sum($torta['data']))->toBe($tarjeta['total']);
});

it('atendidos + no atendidos por encargado suma lo mismo que la tarjeta por etapa', function () {
    test()->actingAs(getDefaultAdmin());

    $cocha = (int) DB::table('lead_pipelines')->where('name', 'Cochabamba')->value('id');
    $admin = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.name', 'Administrador')->first(['users.id', 'users.name']);

    dpsLead(dpsStage($cocha, 'no-atendido'), []);
    dpsLead(dpsStage($cocha, 'cliente-contactado'), []);

    // Asignado a un administrador: la tarjeta lo dejaba afuera.
    $delAdmin = dpsLead(dpsStage($cocha, 'pedidos-entregados'), []);
    DB::table('leads')->where('id', $delAdmin)->update(['user_id' => $admin->id]);

    $porEncargado = dpsStats('leads-attention-by-users');
    $porEtapa = collect(dpsStats('open-leads-by-states-fixed'));

    $i = array_search($admin->name, $porEncargado['labels']);

    expect(array_sum($porEncargado['attended']) + array_sum($porEncargado['not_attended']))->toBe($porEtapa->sum('total'))
        ->and(array_sum($porEncargado['not_attended']))->toBe($porEtapa->firstWhere('name', 'No atendido')['total'])
        ->and($i)->not->toBeFalse()
        ->and($porEncargado['attended'][$i])->toBe(1);
});
