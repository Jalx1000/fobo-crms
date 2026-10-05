<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Distribuidor\Models\UbicacionCliente;
use Webkul\Distribuidor\Services\Bidones;
use Webkul\Distribuidor\Services\Ubicacion;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

/**
 * El control de bidones, la foto opcional y el domicilio georreferenciado.
 *
 * Los bidones son envases que vuelven: el distribuidor deja llenos y se lleva
 * vacíos. Lo que el negocio necesita es cuántos quedaron en la casa del cliente
 * —para ir a buscarlos— y cuántos movió cada distribuidor en el día, para cuadrar
 * contra lo que trae en la camioneta.
 */
uses(DatabaseTransactions::class);

/**
 * Un usuario con el rol Distribuidor.
 *
 * Propio de este archivo: el helper `distribuidor()` del otro test no se carga
 * cuando se corre sólo este, y el error que da —"undefined function"— no se parece
 * en nada a la causa.
 */
function distribuidorDeBidones(): User
{
    $rol = Role::where('name', 'Distribuidor')->firstOrFail();

    return User::create([
        'name'     => 'Distribuidor bidones '.uniqid(),
        'email'    => 'bid'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
        'role_id'  => $rol->id,
        'status'   => 1,
    ]);
}

/** Un cliente nuevo, con su lead en la ciudad que se le pase. */
function clienteConPedido(?int $pipelineId = null): array
{
    $personId = (int) DB::table('persons')->insertGetId([
        'name'       => 'Cliente bidones '.uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pipelineId ??= (int) DB::table('lead_pipelines')->orderBy('id')->value('id');

    $stageId = (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->orderBy('sort_order')
        ->value('id');

    $leadId = (int) DB::table('leads')->insertGetId([
        'title'                  => 'Pedido bidones '.uniqid(),
        'person_id'              => $personId,
        'lead_pipeline_id'       => $pipelineId,
        'lead_pipeline_stage_id' => $stageId,
        'lead_value'             => 1000,
        'user_id'                => 1,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    return ['person_id' => $personId, 'lead_id' => $leadId, 'pipeline_id' => $pipelineId];
}

// ───────────────────────── el saldo del cliente ─────────────────────────

it('el saldo del cliente es lo que se dejó menos lo que se retiró', function () {
    $c = clienteConPedido();
    $bidones = app(Bidones::class);

    expect($bidones->saldoDe($c['person_id']))->toBe(0);

    $bidones->registrar($c['person_id'], $c['lead_id'], 3, 0);
    expect($bidones->saldoDe($c['person_id']))->toBe(3);

    // La semana siguiente: deja 2 y se lleva los 3 vacíos.
    $bidones->registrar($c['person_id'], $c['lead_id'], 2, 3);
    expect($bidones->saldoDe($c['person_id']))->toBe(2);
});

it('no guarda movimiento cuando no hubo bidones', function () {
    $c = clienteConPedido();

    // Una entrega sin envases es lo más común si el cliente compra en botella.
    // Guardar una fila de ceros sólo ensucia el libro.
    expect(app(Bidones::class)->registrar($c['person_id'], $c['lead_id'], 0, 0))->toBeNull()
        ->and(DB::table('distribuidor_bidones')->where('person_id', $c['person_id'])->count())->toBe(0);
});

it('el saldo puede quedar negativo y no se tapa', function () {
    $c = clienteConPedido();

    // Significa que se retiraron más de los que este sistema registró: envases de
    // antes del control, o un movimiento mal cargado. Un max(0) lo esconde justo
    // cuando hay que verlo.
    app(Bidones::class)->registrar($c['person_id'], $c['lead_id'], 1, 4);

    expect(app(Bidones::class)->saldoDe($c['person_id']))->toBe(-3);
});

it('trae los saldos de varios clientes en una sola consulta', function () {
    $a = clienteConPedido();
    $b = clienteConPedido();
    $bidones = app(Bidones::class);

    $bidones->registrar($a['person_id'], null, 5, 1);
    $bidones->registrar($b['person_id'], null, 2, 0);

    $saldos = $bidones->saldosDe([$a['person_id'], $b['person_id']]);

    expect($saldos[$a['person_id']])->toBe(4)
        ->and($saldos[$b['person_id']])->toBe(2);
});

// ───────────────────── lo del día, por distribuidor ─────────────────────

it('la caja del distribuidor cuadra los bidones del día', function () {
    $c = clienteConPedido();
    $user = distribuidorDeBidones();
    $this->actingAs($user, 'user');

    app(Bidones::class)->registrar($c['person_id'], $c['lead_id'], 6, 2);

    $delDia = app(Bidones::class)->delDia($user->id);

    expect($delDia['entregados'])->toBe(6)
        ->and($delDia['retirados'])->toBe(2)
        ->and($delDia['visitas'])->toBe(1)
        // Lo que salió de la camioneta y no volvió: el número del cierre del día.
        ->and($delDia['en_la_calle'])->toBe(4);
});

it('no mezcla los bidones de un distribuidor con los de otro', function () {
    $c = clienteConPedido();
    $uno = distribuidorDeBidones();
    $otro = distribuidorDeBidones();

    app(Bidones::class)->registrar($c['person_id'], null, 7, 0, $uno->id);

    expect(app(Bidones::class)->delDia($uno->id)['entregados'])->toBe(7)
        ->and(app(Bidones::class)->delDia($otro->id)['entregados'])->toBe(0);
});

it('lista a quién hay que recuperarle los envases', function () {
    $c = clienteConPedido();
    app(Bidones::class)->registrar($c['person_id'], $c['lead_id'], 4, 0);

    $pendientes = collect(app(Bidones::class)->pendientesDeDevolucion());
    $fila = $pendientes->firstWhere('person_id', $c['person_id']);

    expect($fila)->not->toBeNull()
        ->and($fila['saldo'])->toBe(4);
});

it('los que ya devolvieron todo no aparecen a recuperar', function () {
    $c = clienteConPedido();
    app(Bidones::class)->registrar($c['person_id'], $c['lead_id'], 3, 3);

    expect(collect(app(Bidones::class)->pendientesDeDevolucion())->firstWhere('person_id', $c['person_id']))
        ->toBeNull();
});

it('a recuperar respeta el alcance por ciudad', function () {
    $pipelines = DB::table('lead_pipelines')->orderBy('id')->pluck('id')->all();

    if (count($pipelines) < 2) {
        $this->markTestSkipped('Hacen falta dos ciudades.');
    }

    $c = clienteConPedido((int) $pipelines[1]);
    app(Bidones::class)->registrar($c['person_id'], $c['lead_id'], 5, 0);

    $otraCiudad = collect(app(Bidones::class)->pendientesDeDevolucion([(int) $pipelines[0]]));

    expect($otraCiudad->firstWhere('person_id', $c['person_id']))->toBeNull();

    $suCiudad = collect(app(Bidones::class)->pendientesDeDevolucion([(int) $pipelines[1]]));

    expect($suCiudad->firstWhere('person_id', $c['person_id']))->not->toBeNull();
});

// ──────────────────── la foto dejó de ser obligatoria ────────────────────

it('cierra la entrega sin foto', function () {
    $c = clienteConPedido();
    $user = distribuidorDeBidones();

    /*
     * Es el requerimiento: exigir la foto bloqueaba la entrega cuando no se podía
     * sacar —sin batería, sin luz, el cliente que no quiere que le fotografíen la
     * puerta—. Un pedido entregado que el sistema no deja cerrar es peor que un
     * pedido cerrado sin foto.
     */
    $this->actingAs($user, 'user')
        ->patchJson(route('admin.distribuidor.pedido.etapa', $c['lead_id']), ['etapa' => 'entregado'])
        ->assertOk();

    $entrega = DB::table('distribuidor_entregas')->where('lead_id', $c['lead_id'])->first();

    expect($entrega)->not->toBeNull()
        ->and($entrega->foto_path)->toBeNull();
});

it('si manda foto la guarda igual', function () {
    Storage::fake('public');

    $c = clienteConPedido();

    $this->actingAs(distribuidorDeBidones(), 'user')
        ->patchJson(route('admin.distribuidor.pedido.etapa', $c['lead_id']), [
            'etapa' => 'entregado',
            'foto'  => UploadedFile::fake()->image('entrega.jpg'),
        ])
        ->assertOk();

    expect(DB::table('distribuidor_entregas')->where('lead_id', $c['lead_id'])->value('foto_path'))
        ->not->toBeNull();
});

it('la entrega registra los bidones de la visita', function () {
    $c = clienteConPedido();

    $this->actingAs(distribuidorDeBidones(), 'user')
        ->patchJson(route('admin.distribuidor.pedido.etapa', $c['lead_id']), [
            'etapa'              => 'entregado',
            'bidones_entregados' => 4,
            'bidones_retirados'  => 1,
        ])
        ->assertOk();

    expect(app(Bidones::class)->saldoDe($c['person_id']))->toBe(3);
});

it('rechaza una cantidad de bidones negativa', function () {
    $c = clienteConPedido();

    $this->actingAs(distribuidorDeBidones(), 'user')
        ->patchJson(route('admin.distribuidor.pedido.etapa', $c['lead_id']), [
            'etapa'              => 'entregado',
            'bidones_entregados' => -2,
        ])
        ->assertStatus(422);
});

// ──────────────── el domicilio georreferenciado del cliente ────────────────

/** Un punto dentro de la zona de reparto configurada. */
function puntoEnZona(): array
{
    $z = (array) config('distribuidor.zona');

    return [
        'lat' => ($z['lat_min'] + $z['lat_max']) / 2,
        'lng' => ($z['lng_min'] + $z['lng_max']) / 2,
    ];
}

it('guarda el domicilio del cliente con el GPS', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();

    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), $p)
        ->assertOk()
        ->assertJsonPath('ubicacion.fuente', UbicacionCliente::FUENTE_GPS);

    expect(app(Ubicacion::class)->deCliente($c['person_id'])['lat'])->toBeGreaterThan(-90);
});

it('rechaza una ubicación fuera de la zona de reparto', function () {
    $c = clienteConPedido();

    /*
     * Un GPS sin señal fina devuelve la ubicación de la antena, que puede caer a
     * cientos de kilómetros. Guardar un domicilio en otra provincia es peor que no
     * guardar nada, porque después nadie lo cuestiona.
     */
    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), [
            'lat' => 40.7128,   // Nueva York
            'lng' => -74.0060,
        ])
        ->assertStatus(422);

    expect(app(Ubicacion::class)->deCliente($c['person_id']))->toBeNull();
});

it('el pin de WhatsApp no pisa el GPS tomado en la puerta', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();
    $ubicacion = app(Ubicacion::class);

    $ubicacion->guardarGps($c['person_id'], $p['lat'], $p['lng']);

    // El cliente manda su ubicación desde el trabajo: no puede borrar el domicilio
    // que el distribuidor marcó bien, o el error aparece en el próximo reparto.
    expect($ubicacion->recordarPin($c['person_id'], $p['lat'] + 0.01, $p['lng'] + 0.01))->toBeFalse()
        ->and($ubicacion->deCliente($c['person_id'])['fuente'])->toBe(UbicacionCliente::FUENTE_GPS);
});

it('el GPS sí pisa un pin de WhatsApp anterior', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();
    $ubicacion = app(Ubicacion::class);

    expect($ubicacion->recordarPin($c['person_id'], $p['lat'], $p['lng']))->toBeTrue();

    $ubicacion->guardarGps($c['person_id'], $p['lat'] + 0.001, $p['lng']);

    expect($ubicacion->deCliente($c['person_id'])['fuente'])->toBe(UbicacionCliente::FUENTE_GPS);
});

it('hay una sola ubicación por cliente', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();
    $ubicacion = app(Ubicacion::class);

    $ubicacion->guardarGps($c['person_id'], $p['lat'], $p['lng']);
    $ubicacion->guardarGps($c['person_id'], $p['lat'] + 0.002, $p['lng']);

    // Esto es el domicilio, no un historial: una fila que se actualiza.
    expect(DB::table('distribuidor_ubicaciones')->where('person_id', $c['person_id'])->count())->toBe(1);
});

it('el domicilio guardado le gana al texto del pedido', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();

    app(Ubicacion::class)->guardarGps($c['person_id'], $p['lat'], $p['lng']);

    $resuelta = app(Ubicacion::class)->dePedido($c['lead_id']);

    expect($resuelta['fuente'])->toBe('cliente_gps')
        ->and($resuelta['modo'])->toBe('pin')
        ->and($resuelta['lat'])->not->toBeNull();
});

it('el pedido expone lo que el dialogo de entrega necesita', function () {
    $c = clienteConPedido();
    app(Bidones::class)->registrar($c['person_id'], $c['lead_id'], 2, 0);

    // "pendientes" lista sólo los confirmados, que es lo que el distribuidor ve en
    // "Para entregar": el pedido tiene que estar en esa etapa para aparecer.
    $confirmado = (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $c['pipeline_id'])
        ->where('code', config('distribuidor.etapas.confirmado'))
        ->value('id');

    if (! $confirmado) {
        $this->markTestSkipped('El pipeline no tiene la etapa de pedido confirmado.');
    }

    DB::table('leads')->where('id', $c['lead_id'])->update(['lead_pipeline_stage_id' => $confirmado]);

    $pedido = $this->actingAs(distribuidorDeBidones(), 'user')
        ->getJson(route('admin.distribuidor.pedidos', ['ciudad' => $c['pipeline_id'], 'filtro' => 'pendientes']))
        ->assertOk()
        ->json('pedidos');

    $fila = collect($pedido)->firstWhere('id', $c['lead_id']);

    /*
     * person_id y bidones los consume el diálogo de entrega: con el primero marca
     * el domicilio con el GPS —entregar es el único momento en que el teléfono está
     * en la puerta— y con el segundo muestra cuántos envases tenía. Si alguien los
     * saca del payload, el botón de GPS desaparece sin ningún error.
     */
    expect($fila)->not->toBeNull()
        ->and($fila['person_id'])->toBe($c['person_id'])
        ->and($fila['bidones'])->toBe(2);
});

it('ningun handler del punto pasa el evento donde va un id', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    /*
     * `@click="metodo"` sin paréntesis le pasa el MouseEvent como primer argumento.
     * Mientras el método no recibía parámetros era inofensivo; el día que se le
     * agregó uno, el evento pasó a ocupar el lugar del id —y no es null, así que
     * sobrevive a un `??`—. La URL quedaba /cliente/[object MouseEvent]/ubicacion y
     * el síntoma era "no me permite registrar ubicación", sin ninguna pista de que
     * el problema fuera el llamado y no el GPS.
     *
     * Se verifican los métodos que reciben algo. Agregar un parámetro a uno de
     * estos y olvidar los paréntesis en la vista vuelve a romperlo en silencio.
     */
    $conParametros = ['marcarUbicacion', 'avanzar', 'abrirEntrega', 'abrirCobro', 'repetir'];

    /*
     * assertStringNotContainsString y no expect()->not->toContain(): toContain()
     * recibe VARIOS needles por varargs, así que un segundo argumento pensado como
     * mensaje se interpreta como otra cosa a buscar y la aserción deja de verificar
     * lo que parece. Esta versión del test pasó con el bug puesto por exactamente
     * eso.
     */
    foreach ($conParametros as $metodo) {
        $this->assertStringNotContainsString(
            '@click="'.$metodo.'"',
            $vista,
            "El handler {$metodo} se llama sin paréntesis en punto.blade.php, así que "
            .'recibe el MouseEvent como primer argumento en vez del id.'
        );
    }
});
