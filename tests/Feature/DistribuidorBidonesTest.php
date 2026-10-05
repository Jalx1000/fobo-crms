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

it('acepta el GPS tomado fuera de la zona de reparto', function () {
    $c = clienteConPedido();

    /*
     * Este test afirmaba lo contrario —que fuera de la zona devolvía 422— y esa regla
     * estaba mal pensada: reusaba la caja que valida la RECONSTRUCCIÓN de los pines
     * de WhatsApp, que llegan mutilados de n8n. El GPS del navegador no se
     * reconstruye, viene bien formado, así que la caja no protegía de nada —cualquier
     * lectura de un distribuidor parado en Jujuy cae dentro igual— y en cambio
     * bloqueaba coordenadas válidas al probar la app desde otra provincia o país.
     *
     * Queda invertido en vez de borrado para que el cambio de criterio sea visible.
     * Lo que ahora juzga la calidad del punto es la precisión, no la geografía.
     */
    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), [
            'lat' => -17.7545,   // Santa Cruz de la Sierra, fuera de la zona
            'lng' => -63.1968,
        ])
        ->assertOk();

    expect(app(Ubicacion::class)->deCliente($c['person_id']))->not->toBeNull();
});

it('rechaza una ubicación con demasiado error', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();

    /*
     * Lo que de verdad distingue la puerta del cliente de la antena de telefonía: el
     * GPS enganchado da 5-20 m, una ubicación por wifi o por antena da cientos.
     */
    $respuesta = $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), [
            'lat'       => $p['lat'],
            'lng'       => $p['lng'],
            'precision' => config('distribuidor.zona.precision_maxima') + 1,
        ])
        ->assertStatus(422);

    // El mensaje dice el número medido y el aceptado: "probá de nuevo" sin decir qué
    // estuvo mal manda a repetir la misma acción esperando otro resultado.
    expect($respuesta->json('message'))->toContain('m de error');

    expect(app(Ubicacion::class)->deCliente($c['person_id']))->toBeNull();
});

it('acepta una ubicación precisa', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();

    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), [
            'lat'       => $p['lat'],
            'lng'       => $p['lng'],
            'precision' => 12,
        ])
        ->assertOk();

    expect(app(Ubicacion::class)->deCliente($c['person_id']))->not->toBeNull();
});

it('guarda igual si el navegador no informa la precisión', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();

    // Mejor una ubicación sin medida de calidad que ninguna.
    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), $p)
        ->assertOk();

    expect(app(Ubicacion::class)->deCliente($c['person_id']))->not->toBeNull();
});

it('el pin de WhatsApp sí se valida contra la zona', function () {
    $c = clienteConPedido();

    /*
     * Acá la caja sí corresponde: el pin llega mutilado de n8n
     * (latitude24193000longitude65297000) y hay que adivinar dónde va el signo y la
     * coma. La zona es lo único que distingue una reconstrucción correcta de un punto
     * cualquiera, así que ese camino conserva la validación que el GPS perdió.
     */
    expect(app(Ubicacion::class)->recordarPin($c['person_id'], -17.7545, -63.1968))->toBeFalse()
        ->and(app(Ubicacion::class)->deCliente($c['person_id']))->toBeNull();
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

// ───────── alta de contacto con domicilio y envases iniciales ─────────

/** Un teléfono que no existe en la base. */
function telefonoNuevo(): string
{
    return '549388'.random_int(1000000, 9999999);
}

it('crea el contacto con su domicilio y sus bidones', function () {
    $p = puntoEnZona();
    $telefono = telefonoNuevo();

    $persona = $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => $telefono,
            'nombre'   => 'Cliente nuevo '.uniqid(),
            'lat'      => $p['lat'],
            'lng'      => $p['lng'],
            'bidones'  => 3,
        ])
        ->assertOk()
        ->json('persona');

    /*
     * Los dos datos se cargan JUNTO con el contacto y no en una pantalla aparte:
     * son los que el distribuidor tiene a mano justo cuando conoce al cliente, y
     * pedirlos después significa que no se cargan nunca.
     */
    expect($persona['bidones'])->toBe(3)
        ->and($persona['ubicacion'])->not->toBeNull()
        ->and($persona['ubicacion']['fuente'])->toBe(UbicacionCliente::FUENTE_MAPA);
});

it('crea el contacto sin domicilio ni bidones', function () {
    // Un contacto se puede dar de alta por teléfono sin saber todavía dónde vive.
    $persona = $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => telefonoNuevo(),
            'nombre'   => 'Cliente sin datos '.uniqid(),
        ])
        ->assertOk()
        ->json('persona');

    expect($persona['bidones'])->toBe(0)
        ->and($persona['ubicacion'])->toBeNull();
});

it('rechaza media coordenada', function () {
    $p = puntoEnZona();

    // Sólo latitud no ubica nada, y guardarla a medias dejaría un domicilio inválido
    // que después nadie revisa.
    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => telefonoNuevo(),
            'nombre'   => 'Cliente medio '.uniqid(),
            'lat'      => $p['lat'],
        ])
        ->assertStatus(422);
});

it('el domicilio del alta acepta coordenadas de cualquier lugar', function () {
    // Mismo criterio que el GPS: la caja de zona es para reconstruir pines, no para
    // juzgar un punto que una persona eligió mirando el mapa.
    $persona = $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => telefonoNuevo(),
            'nombre'   => 'Cliente lejos '.uniqid(),
            'lat'      => -17.7545,
            'lng'      => -63.1968,
        ])
        ->assertOk()
        ->json('persona');

    expect($persona['ubicacion'])->not->toBeNull();
});

it('el pin de WhatsApp no pisa el domicilio puesto en el mapa', function () {
    $p = puntoEnZona();
    $ubicacion = app(Ubicacion::class);

    $personaId = (int) $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => telefonoNuevo(),
            'nombre'   => 'Cliente mapa '.uniqid(),
            'lat'      => $p['lat'],
            'lng'      => $p['lng'],
        ])
        ->assertOk()
        ->json('persona.id');

    /*
     * El punto del mapa lo eligió una persona mirando el domicilio, así que vale
     * tanto como el GPS de la puerta: un pin que el cliente manda desde el trabajo
     * no puede borrarlo.
     */
    expect($ubicacion->recordarPin($personaId, $p['lat'] + 0.01, $p['lng'] + 0.01))->toBeFalse()
        ->and($ubicacion->deCliente($personaId)['fuente'])->toBe(UbicacionCliente::FUENTE_MAPA);
});

it('el GPS sí corrige el domicilio puesto en el mapa', function () {
    $p = puntoEnZona();
    $ubicacion = app(Ubicacion::class);

    $personaId = (int) $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.contacto.store'), [
            'telefono' => telefonoNuevo(),
            'nombre'   => 'Cliente corregible '.uniqid(),
            'lat'      => $p['lat'],
            'lng'      => $p['lng'],
        ])
        ->assertOk()
        ->json('persona.id');

    // Estando en la puerta, el GPS es más preciso que el dedo sobre el mapa.
    $ubicacion->guardarGps($personaId, $p['lat'] + 0.001, $p['lng'], null, 10.0);

    expect($ubicacion->deCliente($personaId)['fuente'])->toBe(UbicacionCliente::FUENTE_GPS);
});

// ───────── editar el domicilio sobre el mapa ─────────

it('guarda el domicilio editado en el mapa', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();

    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), [
            'lat'    => $p['lat'],
            'lng'    => $p['lng'],
            'origen' => 'mapa',
        ])
        ->assertOk()
        ->assertJsonPath('ubicacion.fuente', UbicacionCliente::FUENTE_MAPA);
});

it('al punto del mapa no se le mide la precision', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();

    /*
     * El chequeo de precisión existe para atajar un GPS que nadie miró. Un punto
     * confirmado sobre el mapa lo vio una persona, así que no se le aplica: si no,
     * arrastrar el pin después de un GPS impreciso quedaría bloqueado justamente
     * cuando se está corrigiendo ese error.
     */
    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), [
            'lat'       => $p['lat'],
            'lng'       => $p['lng'],
            'precision' => 5000,
            'origen'    => 'mapa',
        ])
        ->assertOk();

    expect(app(Ubicacion::class)->deCliente($c['person_id']))->not->toBeNull();
});

it('sin origen sigue tratandose como GPS', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();

    // El botón del diálogo de entrega no manda origen: ese camino no puede cambiar
    // de semántica por haber agregado el parámetro.
    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), [
            'lat'       => $p['lat'],
            'lng'       => $p['lng'],
            'precision' => config('distribuidor.zona.precision_maxima') + 1,
        ])
        ->assertStatus(422);
});

it('rechaza un origen inventado', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();

    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), [
            'lat'    => $p['lat'],
            'lng'    => $p['lng'],
            'origen' => 'adivinado',
        ])
        ->assertStatus(422);
});

it('editar el domicilio pisa el que ya estaba', function () {
    $c = clienteConPedido();
    $p = puntoEnZona();
    $ubicacion = app(Ubicacion::class);

    $ubicacion->guardarGps($c['person_id'], $p['lat'], $p['lng'], null, 10.0);

    $corregido = ['lat' => $p['lat'] + 0.002, 'lng' => $p['lng'] + 0.002];

    $this->actingAs(distribuidorDeBidones(), 'user')
        ->postJson(route('admin.distribuidor.cliente.ubicacion', $c['person_id']), $corregido + ['origen' => 'mapa'])
        ->assertOk();

    // Una fila por cliente: esto es el domicilio, no un historial.
    expect(DB::table('distribuidor_ubicaciones')->where('person_id', $c['person_id'])->count())->toBe(1)
        ->and(round($ubicacion->deCliente($c['person_id'])['lat'], 4))->toBe(round($corregido['lat'], 4));
});

it('el domicilio marcado se muestra, no solo se anuncia', function () {
    $html = $this->actingAs(distribuidorDeBidones(), 'user')
        ->get(route('admin.distribuidor.index'))
        ->assertOk()
        ->getContent();

    $i = strpos($html, 'id="v-punto-template"');
    $tpl = substr($html, $i, strpos($html, '</script>', $i) - $i);

    /*
     * Antes la ficha decía "Domicilio marcado" y nada más: confirmaba que había un
     * dato pero no dejaba comprobar si estaba bien, y un pin a dos cuadras se ve
     * igual que uno correcto cuando sólo hay una etiqueta. Ahora se dibuja el punto.
     */
    $this->assertStringContainsString('ref="mapaVista"', $tpl, 'Falta la vista del domicilio en la ficha del cliente: ');
    $this->assertStringContainsString('v-if="cliente.ubicacion"', $tpl, 'La vista del domicilio no está condicionada a que exista: ');

    // Y en el diálogo de entrega, marcar deja un enlace para comprobar dónde cayó.
    $this->assertStringContainsString('entrega.ubicacion', $tpl, 'El diálogo de entrega no muestra el domicilio marcado: ');

    /*
     * La vista usa estado propio y no el del editor: los dos pueden estar en pantalla
     * a la vez —el editor se abre DESDE la vista— y un solo objeto de Leaflet no
     * puede dibujarse en dos contenedores.
     */
    $this->assertStringContainsString('vistaMapa', $html, 'La vista comparte el objeto del editor: ');
});

it('todo lo clickeable del punto tiene anillo de foco', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    /*
     * Sin anillo de foco, con teclado no se ve dónde estás parado: el Punto se usa
     * sobre todo con el dedo, pero la pantalla también se opera desde una notebook en
     * el depósito, y ahí un botón sin foco visible es un botón que no se encuentra.
     *
     * Se revisa la etiqueta COMPLETA y no la línea: en este archivo la clase va en la
     * línea siguiente a <button, así que un grep por línea da todo falso positivo.
     */
    preg_match_all('/<(button|a)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>/s', $vista, $tags, PREG_SET_ORDER);

    expect(count($tags))->toBeGreaterThan(20);

    $sinFoco = [];
    $sinMotionReduce = [];

    foreach ($tags as $t) {
        $attrs = $t[2];

        if (! str_contains($attrs, 'focus:ring') && ! str_contains($attrs, 'focus-visible:ring')) {
            $sinFoco[] = mb_substr(preg_replace('/\s+/', ' ', $t[0]), 0, 90);
        }

        // prefers-reduced-motion: una transición sin esto marea a quien lo pidió.
        if (str_contains($attrs, 'transition') && ! str_contains($attrs, 'motion-reduce')) {
            $sinMotionReduce[] = mb_substr(preg_replace('/\s+/', ' ', $t[0]), 0, 90);
        }
    }

    $this->assertSame([], $sinFoco, 'Elementos interactivos sin anillo de foco.');
    $this->assertSame([], $sinMotionReduce, 'Transiciones que ignoran prefers-reduced-motion.');
});

it('el mapa se puede marcar sin mouse', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    /*
     * Tocar el mapa y arrastrar el marcador son gestos de puntero, y Leaflet con las
     * flechas mueve el mapa pero NO el pin. Sin un botón que ponga el pin en el centro,
     * marcar un domicilio era imposible con teclado.
     */
    $this->assertStringContainsString('pinAlCentro', $vista, 'Falta el camino de teclado para marcar el domicilio: ');
});

/**
 * El contenido de un <div> desde `desde` hasta su </div> correspondiente.
 *
 * Contando apertura y cierre, no por cantidad de caracteres: así el recorte termina
 * donde termina el bloque y no arrastra lo que viene después.
 */
function bloqueDiv(string $html, int $desde): string
{
    $profundidad = 1;
    $i = $desde;
    $largo = strlen($html);

    while ($i < $largo && $profundidad > 0) {
        $abre = strpos($html, '<div', $i);
        $cierra = strpos($html, '</div>', $i);

        if ($cierra === false) {
            break;
        }

        if ($abre !== false && $abre < $cierra) {
            $profundidad++;
            $i = $abre + 4;

            continue;
        }

        $profundidad--;
        $i = $cierra + 6;
    }

    return substr($html, $desde, $i - $desde);
}

it('ningun importe queda en una celda angosta en el celular', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    /*
     * A 375px —el ancho real del celular del distribuidor— una grilla de tres
     * columnas deja unos 105px por celda, y un importe de seis cifras en text-2xl
     * necesita más del doble: el número se parte en dos líneas por el medio.
     *
     * La regla: una grilla que contenga money() tiene que arrancar en una o dos
     * columnas y recién ensanchar desde sm. Se valida sobre el CONTENIDO de la
     * grilla y no sobre la clase sola, porque grid-cols-3 está bien para las
     * pestañas y para los contadores de bidones, que son palabras cortas y enteros.
     */
    preg_match_all('/<div[^>]*class="[^"]*\bgrid-cols-3\b[^"]*"[^>]*>/', $vista, $grids, PREG_OFFSET_CAPTURE);

    foreach ($grids[0] as [$tag, $pos]) {
        // Es responsive si la clase declara un arranque menor y sube en sm/md/lg.
        $responsive = (bool) preg_match('/\b(sm|md|lg):grid-cols-/', $tag);

        if ($responsive) {
            continue;
        }

        /*
         * El contenido REAL de la grilla: se avanza contando <div> y </div> hasta
         * cerrar el bloque. Un recorte por cantidad de caracteres se pasa del cierre y
         * lee marcado de al lado: la primera versión de este test daba por rota la
         * grilla de las pestañas porque más abajo, fuera de ella, había un money().
         */
        $contenido = bloqueDiv($vista, $pos + mb_strlen($tag));

        $this->assertStringNotContainsString(
            'money(',
            $contenido,
            'Una grilla de 3 columnas sin variante móvil muestra importes; a 375px el número se corta. '
            .'Usá grid-cols-2 sm:grid-cols-3.'
        );
    }
});

it('las formas de pago no se aprietan en el celular', function () {
    $vista = file_get_contents(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    );

    // "Transferencia" en 106px se parte o desborda según la fuente del sistema.
    $i = strpos($vista, 'v-for="(label, key) in formasPago"');

    expect($i)->not->toBeFalse();

    /*
     * Se busca la grilla que CONTIENE a los botones, hacia atrás desde el v-for, en vez
     * de recortar una ventana de N caracteres: un comentario agregado arriba corre el
     * marcado y la ventana deja de alcanzar, con lo que el test falla por su propio
     * recorte y no por el código. Pasó en la primera versión.
     */
    $antes = substr($vista, 0, $i);
    $grid = strrpos($antes, '<div class="mb-4 grid');

    expect($grid)->not->toBeFalse('No se encontró la grilla de las formas de pago.');

    $this->assertStringContainsString(
        'sm:grid-cols-3',
        substr($antes, $grid, $i - $grid),
        'Las formas de pago tienen que apilarse en el celular y recién abrirse en sm. '
        .'En tres columnas a 375px "Transferencia" no entra.'
    );
});
