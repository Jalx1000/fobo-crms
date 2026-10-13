<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/**
 * Tablero: «Atendidos vs no atendidos por encargado» y «Productos más solicitados».
 *
 * - No atendidos salía siempre en 0: se buscaba la etapa «no atendido» y en el
 *   CRM se llama NO ATENIDOS (código `no-atenidos`).
 * - Productos más solicitados contaba los productos cargados en el pedido, que
 *   casi nunca tiene ninguno (en octubre: 4 Stretch Film). Los productos están
 *   en las cotizaciones; se cuenta en cuántas cotizaciones aparece cada uno.
 * - Ninguna de las dos cuenta los pedidos del pipeline «Sin ciudad».
 *
 * Todo en enero de 2020, para no mezclarse con los datos reales.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(User::findOrFail(1), 'user');

    $this->asesor = User::whereHas('role', fn ($q) => $q->whereNotIn('name', ['Administrador', 'Supervisores', 'Sistema']))
        ->where('status', 1)->where('name', '!=', 'Agente')->firstOrFail();

    $this->sinCiudad = (int) DB::table('lead_pipelines')->where('name', 'Sin ciudad')->value('id');
    $this->fecha = '2020-01-15 10:00:00';

    $this->persona = DB::table('persons')->insertGetId([
        'name' => 'Tarjeta '.uniqid(), 'created_at' => $this->fecha, 'updated_at' => $this->fecha,
    ]);
});

function tarjetaPedido($test, int $pipeline, string $etapa): int
{
    return DB::table('leads')->insertGetId([
        'title'                  => 'Tarjeta '.uniqid(), 'user_id' => $test->asesor->id, 'status' => 1,
        'lead_pipeline_id'       => $pipeline,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $pipeline)->where('code', $etapa)->value('id'),
        'created_at'             => $test->fecha, 'updated_at' => $test->fecha,
    ]);
}

function tarjetaCotizacion($test, int $lead, string $sku, int $cantidad): void
{
    $quote = DB::table('quotes')->insertGetId([
        'subject'    => 'Tarjeta', 'user_id' => $test->asesor->id, 'person_id' => $test->persona, 'sub_total' => 0, 'grand_total' => 0,
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

it('cuenta los NO ATENIDOS como no atendidos', function () {
    tarjetaPedido($this, 1, 'no-atenidos');
    tarjetaPedido($this, 1, 'no-atenidos');
    tarjetaPedido($this, 1, 'solicitud-recibida');

    $s = tarjeta('leads-attention-by-users');
    $i = array_search($this->asesor->name, $s['labels']);

    expect($s['not_attended'][$i])->toBe(2)
        ->and($s['attended'][$i])->toBe(1);
});

it('atendidos vs no atendidos no cuenta los pedidos Sin ciudad', function () {
    tarjetaPedido($this, 1, 'no-atenidos');
    tarjetaPedido($this, $this->sinCiudad, 'no-atenidos');
    tarjetaPedido($this, $this->sinCiudad, 'solicitud-recibida');

    $s = tarjeta('leads-attention-by-users');
    $i = array_search($this->asesor->name, $s['labels']);

    expect($s['not_attended'][$i])->toBe(1)
        ->and($s['attended'][$i])->toBe(0);
});

it('productos más solicitados cuenta en cuántas cotizaciones aparece cada producto', function () {
    $lead = tarjetaPedido($this, 1, 'solicitud-recibida');
    tarjetaCotizacion($this, $lead, 'CM_00002', 10);
    tarjetaCotizacion($this, $lead, 'CM_00002', 5);
    tarjetaCotizacion($this, $lead, 'CM_00001', 40);

    $s = collect(tarjeta('quantity-products'));

    expect($s->pluck('name')->first())->toBe('Bolsas Magia Verde')
        ->and($s->firstWhere('name', 'Bolsas Magia Verde')['solicitudes'])->toBe(2)
        ->and((int) $s->firstWhere('name', 'Bolsas Magia Verde')['total_qty_ordered'])->toBe(15)
        ->and($s->firstWhere('name', 'Stretch Film')['solicitudes'])->toBe(1);
});

it('productos más solicitados no cuenta cotizaciones de pedidos Sin ciudad', function () {
    $conCiudad = tarjetaPedido($this, 1, 'solicitud-recibida');
    $sinCiudad = tarjetaPedido($this, $this->sinCiudad, 'solicitud-recibida');

    tarjetaCotizacion($this, $conCiudad, 'CM_00003', 1);
    tarjetaCotizacion($this, $sinCiudad, 'CM_00003', 1);
    tarjetaCotizacion($this, $sinCiudad, 'CM_00004', 1);

    $s = collect(tarjeta('quantity-products'));

    expect($s->firstWhere('name', 'Hules')['solicitudes'])->toBe(1)
        ->and($s->pluck('name'))->not->toContain('Tapas');
});

it('no atendidos por ciudad no muestra la porción Sin ciudad', function () {
    tarjetaPedido($this, 1, 'no-atenidos');
    tarjetaPedido($this, $this->sinCiudad, 'no-atenidos');

    $s = tarjeta('leads-no-atendidos-por-ciudad');

    expect($s['labels'])->not->toContain('Sin ciudad')
        ->and($s['labels'])->toContain(DB::table('lead_pipelines')->where('id', 1)->value('name'));
});

it('atendidos vs no atendidos no trae la barra del usuario del agente', function () {
    expect(tarjeta('leads-attention-by-users')['labels'])->not->toContain('Agente')
        ->not->toContain('Sin ciudad');
});
