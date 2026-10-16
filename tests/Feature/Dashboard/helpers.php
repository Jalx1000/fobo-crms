<?php

use Illuminate\Support\Facades\DB;

/*
| Helpers de los tests del tablero (Dashboard). Esperan en $test: asesor,
| persona y fecha (ver el beforeEach de cada archivo). Fechas de enero de
| 2020, para no mezclarse con los datos reales.
*/

function tarjetaPedido($test, int $pipeline, string $etapa): int
{
    return DB::table('leads')->insertGetId([
        'title'                  => 'Tarjeta '.uniqid(), 'user_id' => $test->asesor->id, 'status' => 1,
        'lead_pipeline_id'       => $pipeline,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->where('code', $etapa)->value('id'),
        'created_at'             => $test->fecha, 'updated_at' => $test->fecha,
    ]);
}

function tarjetaCotizacion($test, int $lead, string $sku, int $cantidad, float $total = 0): void
{
    $quote = DB::table('quotes')->insertGetId([
        'subject'    => 'Tarjeta', 'user_id' => $test->asesor->id, 'person_id' => $test->persona, 'sub_total' => $total, 'grand_total' => $total,
        'created_at' => $test->fecha, 'updated_at' => $test->fecha,
    ]);

    $producto = DB::table('products')->where('sku', $sku)->first();

    DB::table('quote_items')->insert([
        'quote_id' => $quote, 'product_id' => $producto->id, 'sku' => $sku, 'name' => $producto->name,
        'quantity' => $cantidad, 'price' => 0, 'total' => 0, 'created_at' => $test->fecha, 'updated_at' => $test->fecha,
    ]);

    DB::table('lead_quotes')->insert(['lead_id' => $lead, 'quote_id' => $quote]);
}

function tarjeta(string $tipo): array
{
    return test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => $tipo, 'start' => '2020-01-01', 'end' => '2020-01-31']))
        ->assertOk()
        ->json('statistics');
}
