<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/**
 * Tablero por asesor (spec docs/specs/tablero-por-asesor.md): un ejecutivo
 * ve solo sus números; Administrador y Supervisores ven el total.
 *
 * Bulmaro y Eduardo son ejecutivos reales (permiso individual); Mariano es
 * supervisor. Todo en enero de 2020 y dentro de una transacción.
 */
require_once __DIR__.'/helpers.php';

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $this->mariano = User::where('name', 'Mariano Pinell')->firstOrFail();
    $this->javier = User::findOrFail(1);

    $this->fecha = '2020-01-15 10:00:00';
    $this->persona = DB::table('persons')->insertGetId([
        'name' => 'Por asesor '.uniqid(), 'created_at' => $this->fecha, 'updated_at' => $this->fecha,
    ]);

    // 5 rollos de Bulmaro y 8 de Eduardo, cotizados en enero de 2020.
    $this->asesor = $this->bulmaro;
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'cotizacion-enviada'), 'CM_00001', 5);
    $this->asesor = $this->eduardo;
    tarjetaCotizacion($this, tarjetaPedido($this, 1, 'cotizacion-enviada'), 'CM_00001', 8);
});

function solicitadosComo(User $usuario, array $extra = []): float
{
    test()->actingAs($usuario, 'user');

    return (float) test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => 'over-all', 'start' => '2020-01-01', 'end' => '2020-01-31'] + $extra))
        ->assertOk()
        ->json('statistics.total_services.current');
}

it('un ejecutivo ve solo lo de sus pedidos', function () {
    expect(solicitadosComo($this->bulmaro))->toBe(5.0)
        ->and(solicitadosComo($this->eduardo))->toBe(8.0);
});

it('administrador y supervisor ven el total', function () {
    expect(solicitadosComo($this->javier))->toBe(13.0)
        ->and(solicitadosComo($this->mariano))->toBe(13.0);
});

it('un ejecutivo no puede pedir por URL los números de otro', function () {
    expect(solicitadosComo($this->bulmaro, ['user_id' => $this->eduardo->id]))->toBe(5.0);
});

it('el alcance no depende del permiso de vista: un supervisor con permiso individual ve el total', function () {
    $this->mariano->view_permission = 'individual';
    $this->mariano->save();

    expect(solicitadosComo($this->mariano->fresh()))->toBe(13.0);
});

it('los prospectos del tablero (consultas de Eloquent) también quedan en el alcance', function () {
    test()->actingAs($this->bulmaro, 'user');

    $prospectos = test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => 'over-all', 'start' => '2020-01-01', 'end' => '2020-01-31']))
        ->json('statistics.total_leads.current');

    expect($prospectos)->toBe(1);
});

/**
 * Criterio de éxito 1 y 5 del spec: NINGUNA tarjeta le muestra a un
 * ejecutivo datos de otro. Se pide cada `type` como Bulmaro, se cargan
 * pedidos, cotizaciones, contacto y organización solo de Eduardo, y se vuelve
 * a pedir: la respuesta de Bulmaro no puede cambiar.
 */
it('ningún type del tablero le muestra a un ejecutivo los datos de otro', function () {
    // «Tiempo en responder» mide contra ahora: sin reloj fijo, la barra del
    // propio Bulmaro cambia unos segundos entre las dos pasadas.
    $this->freezeTime();

    $tipos = array_keys((new ReflectionClass(\Webkul\Admin\Http\Controllers\DashboardController::class))
        ->getDefaultProperties()['typeFunctions']);

    $pedir = function () use ($tipos) {
        test()->actingAs($this->bulmaro, 'user');

        return collect($tipos)->mapWithKeys(fn ($tipo) => [$tipo => test()->withHeaders(['Accept' => 'application/json'])
            ->get(route('admin.dashboard.stats', ['type' => $tipo, 'start' => '2020-01-01', 'end' => '2020-01-31']))
            ->assertOk()
            ->json('statistics')]);
    };

    $antes = $pedir();

    // Todo de Eduardo, en enero de 2020.
    $this->asesor = $this->eduardo;
    $organizacion = DB::table('organizations')->insertGetId([
        'name' => 'Org Eduardo '.uniqid(), 'user_id' => $this->eduardo->id, 'created_at' => $this->fecha, 'updated_at' => $this->fecha,
    ]);
    DB::table('persons')->insert([
        'name' => 'Cliente Eduardo '.uniqid(), 'user_id' => $this->eduardo->id, 'organization_id' => $organizacion,
        'created_at' => $this->fecha, 'updated_at' => $this->fecha,
    ]);
    foreach (['no-atendidos', 'solicitud-recibida', 'cotizacion-enviada', 'entregados', 'cancelados', 'sin-inters'] as $etapa) {
        $pedido = tarjetaPedido($this, 7, $etapa);
        DB::table('leads')->where('id', $pedido)->update(['lead_value' => 1000, 'closed_at' => $this->fecha, 'person_id' => $this->persona]);
        tarjetaCotizacion($this, $pedido, 'CM_00002', 20, 300);
        DB::table('lead_products')->insert([
            'lead_id' => $pedido, 'product_id' => DB::table('products')->where('sku', 'CM_00002')->value('id'),
            'quantity' => 20, 'price' => 15, 'amount' => 300, 'created_at' => $this->fecha, 'updated_at' => $this->fecha,
        ]);
    }

    $despues = $pedir();

    // Todas las fugas juntas (tarjeta.campo), no solo la primera.
    $fugas = [];
    foreach ($tipos as $tipo) {
        $a = \Illuminate\Support\Arr::dot((array) $antes[$tipo]);
        $d = \Illuminate\Support\Arr::dot((array) $despues[$tipo]);
        foreach (array_keys($a + $d) as $campo) {
            if (($a[$campo] ?? null) != ($d[$campo] ?? null)) {
                $fugas[] = "{$tipo}.{$campo}";
            }
        }
    }

    expect($fugas)->toBe([], 'Tarjetas que le muestran a Bulmaro datos de Eduardo');
});

/**
 * A3 (ex T6 de tablero-consistente, D7): los rankings por asesor muestran solo
 * ejecutivos comerciales; los pedidos de otros (supervisor, agente, sin
 * asesor) no se pierden: van a `otros` y las tarjetas por ciudad los cuentan.
 */
function pedidoDe($test, ?int $userId, string $etapa = 'no-atendidos'): int
{
    $id = tarjetaPedido($test, 1, $etapa);
    DB::table('leads')->where('id', $id)->update(['user_id' => $userId]);

    return $id;
}

function tarjetaComo(User $usuario, string $tipo): array
{
    test()->actingAs($usuario, 'user');

    return test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => $tipo, 'start' => '2020-01-01', 'end' => '2020-01-31']))
        ->assertOk()->json('statistics');
}

it('los rankings por asesor muestran solo ejecutivos y juntan el resto en otros', function () {
    pedidoDe($this, $this->mariano->id);   // Supervisor
    pedidoDe($this, 13);                   // Agente (Sistema)
    pedidoDe($this, null);                 // sin asesor

    foreach (['leads-attention-by-users', 'leads-by-users', 'ventas'] as $tipo) {
        $s = tarjetaComo($this->javier, $tipo);

        expect($s['labels'])->not->toContain('Mariano Pinell')
            ->and($s['labels'])->not->toContain('Agente')
            ->and($s['labels'])->not->toContain('Andres Gonzalez Requena')
            ->and($s['labels'])->toContain('Bulmaro Vaca');
    }

    // Atendidos vs no atendidos: 2 pedidos de ejecutivos (beforeEach) + 3 de otros.
    $s = tarjetaComo($this->javier, 'leads-attention-by-users');
    expect($s['otros'])->toBe(3)
        ->and(array_sum($s['attended']) + array_sum($s['not_attended']) + $s['otros'])->toBe(5);
});

it('un ejecutivo ve solo su fila en los rankings y ningún otros ajeno', function () {
    pedidoDe($this, $this->mariano->id);

    foreach (['leads-attention-by-users', 'leads-by-users', 'ventas', 'tiempo-por-vendedor'] as $tipo) {
        expect(tarjetaComo($this->bulmaro, $tipo)['labels'])->toBe(['Bulmaro Vaca']);
    }

    expect(tarjetaComo($this->bulmaro, 'leads-attention-by-users')['otros'])->toBe(0);
});

it('las tarjetas por ciudad cuentan todos los pedidos, también los de no ejecutivos', function () {
    pedidoDe($this, $this->mariano->id);
    pedidoDe($this, null);

    $s = tarjetaComo($this->javier, 'pedidos-por-ciudad');
    $i = array_search('Santa Cruz', $s['labels']);

    // 2 cotizaciones del beforeEach + los 2 pedidos de no ejecutivos.
    expect($s['data'][$i])->toBe(4);
});

/**
 * A4 (D7 del spec): el Excel respeta el mismo alcance que el tablero, por rol.
 */
function filasExcelComo(User $usuario): array
{
    test()->actingAs($usuario, 'user');
    request()->merge(['date_filter' => 'custom', 'date_from' => '2020-01-01', 'date_to' => '2020-01-31', 'pipeline_id' => 'all']);

    return collect((new \Webkul\Admin\Exports\Dashboard\DashboardOrdersSheet)->array())
        ->map(fn ($fila) => implode('|', array_map('strval', $fila)))
        ->all();
}

it('el Excel de un ejecutivo trae solo sus pedidos y el de un supervisor todos, aunque tenga permiso individual', function () {
    $deBulmaro = collect(filasExcelComo($this->bulmaro));
    expect($deBulmaro)->toHaveCount(1)
        ->and($deBulmaro->first())->toContain('Bulmaro Vaca');

    $this->mariano->view_permission = 'individual';
    $this->mariano->save();

    expect(filasExcelComo($this->mariano->fresh()))->toHaveCount(2);
});

it('los títulos dicen «Mis …» solo para quien ve lo suyo', function () {
    test()->actingAs($this->bulmaro, 'user');
    $deBulmaro = test()->get(route('admin.dashboard.index'))->assertOk()->getContent();

    test()->actingAs($this->mariano, 'user');
    $deMariano = test()->get(route('admin.dashboard.index'))->assertOk()->getContent();

    foreach (['Mis productos solicitados', 'Mis productos más solicitados', 'Mis pedidos por etapa', 'Mis ventas'] as $titulo) {
        expect($deBulmaro)->toContain($titulo)
            ->and($deMariano)->not->toContain($titulo);
    }

    expect($deMariano)->toContain('Total productos solicitados');
});
