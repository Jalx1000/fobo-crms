<?php

use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Services\ResponseTimeReport;

/*
 * Tiempo de respuesta en WhatsApp, separando el agente IA de los asesores.
 *
 * El tablero ya tenía "Tiempo en responder", pero mide el ciclo de vida del
 * pedido. Mezclar al bot con las personas tampoco servía: medido sobre 30 días,
 * el agente responde con mediana de 0,2 min y el asesor de 305 min, así que un
 * promedio conjunto da ~17 min y esconde que un cliente derivado espera horas.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    DB::beginTransaction();
    actingAsSanctumAuthenticatedAdmin();
    test()->actingAs(getDefaultAdmin(), 'user');
});

afterEach(fn () => DB::rollBack());

function convParaTiempos(): int
{
    return (int) DB::table('whatsapp_conversations')->insertGetId([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '+5917'.random_int(1000000, 9999999),
        'wa_name'    => 'Tiempos '.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function mensaje(int $conv, string $direction, ?string $sender, string $cuando, ?int $userId = null, string $status = 'delivered'): int
{
    return (int) DB::table('whatsapp_messages')->insertGetId([
        'conversation_id' => $conv,
        'direction'       => $direction,
        'type'            => 'text',
        'body'            => 'x',
        'sender'          => $sender,
        'user_id'         => $userId,
        'status'          => $status,
        'created_at'      => $cuando,
        'updated_at'      => $cuando,
    ]);
}

/**
 * Acotado a la conversación del test: la suite corre contra producción y el
 * tráfico real de la última hora se mezclaría con lo que inserta cada caso.
 */
function reporte(int $conv): array
{
    return (new ResponseTimeReport(now()->subDay(), now()->addDay()))
        ->soloConversaciones([$conv])
        ->build();
}

it('mide los minutos entre el mensaje del cliente y la respuesta', function () {
    $c = convParaTiempos();

    mensaje($c, 'inbound', 'contact', now()->subMinutes(30)->toDateTimeString());
    mensaje($c, 'outbound', 'ia', now()->subMinutes(28)->toDateTimeString());

    $d = reporte($c);

    expect($d['agente']['respuestas'])->toBe(1)
        ->and($d['agente']['mediana'])->toBe(2.0);
});

it('separa al agente de los asesores', function () {
    $c = convParaTiempos();

    mensaje($c, 'inbound', 'contact', now()->subMinutes(60)->toDateTimeString());
    mensaje($c, 'outbound', 'ia', now()->subMinutes(59)->toDateTimeString());
    mensaje($c, 'inbound', 'contact', now()->subMinutes(50)->toDateTimeString());
    mensaje($c, 'outbound', 'human', now()->subMinutes(20)->toDateTimeString());

    $d = reporte($c);

    expect($d['agente']['respuestas'])->toBe(1)
        ->and($d['agente']['mediana'])->toBe(1.0)
        ->and($d['asesor']['respuestas'])->toBe(1)
        ->and($d['asesor']['mediana'])->toBe(30.0);
});

it('cuenta desde el ÚLTIMO mensaje del cliente, no el primero', function () {
    $c = convParaTiempos();

    // Tres mensajes seguidos del cliente: el reloj arranca cuando terminó.
    mensaje($c, 'inbound', 'contact', now()->subMinutes(40)->toDateTimeString());
    mensaje($c, 'inbound', 'contact', now()->subMinutes(35)->toDateTimeString());
    mensaje($c, 'inbound', 'contact', now()->subMinutes(30)->toDateTimeString());
    mensaje($c, 'outbound', 'ia', now()->subMinutes(25)->toDateTimeString());

    // 5 min desde el último, no 15 desde el primero.
    expect(reporte($c)['agente']['mediana'])->toBe(5.0);
});

it('ignora los mensajes que fallaron: son un intento, no una respuesta', function () {
    $c = convParaTiempos();

    mensaje($c, 'inbound', 'contact', now()->subMinutes(30)->toDateTimeString());
    mensaje($c, 'outbound', 'ia', now()->subMinutes(20)->toDateTimeString(), null, 'failed');

    expect(reporte($c)['agente']['respuestas'])->toBe(0);
});

it('ignora una respuesta sin ningún mensaje previo del cliente', function () {
    $c = convParaTiempos();

    // Primer contacto nuestro: no hay espera que medir.
    mensaje($c, 'outbound', 'ia', now()->subMinutes(10)->toDateTimeString());

    expect(reporte($c)['agente']['respuestas'])->toBe(0);
});

it('atribuye la respuesta al asesor que la escribió', function () {
    $c = convParaTiempos();
    $asesor = (int) DB::table('users')->where('status', 1)->value('id');

    mensaje($c, 'inbound', 'contact', now()->subMinutes(60)->toDateTimeString());
    mensaje($c, 'outbound', 'human', now()->subMinutes(50)->toDateTimeString(), $asesor);

    $porAsesor = reporte($c)['por_asesor'];

    expect($porAsesor)->toHaveCount(1)
        ->and($porAsesor[0]['respuestas'])->toBe(1)
        ->and($porAsesor[0]['mediana'])->toBe(10.0);
});

it('usa la mediana y el p90, no el promedio, porque la cola manda', function () {
    /*
     * Nueve respuestas de 1 minuto y una de 10 horas.
     *
     * Van en conversaciones separadas a propósito: el reloj arranca en el
     * ÚLTIMO entrante anterior a cada respuesta, así que mezclarlas en una sola
     * charla hace que los mensajes rápidos se interpongan y el par lento nunca
     * se forme. Primera versión de este test cayó justo en eso.
     */
    $rapidas = [];

    foreach (range(1, 9) as $i) {
        $c = convParaTiempos();
        mensaje($c, 'inbound', 'contact', now()->subMinutes(120 + $i)->toDateTimeString());
        mensaje($c, 'outbound', 'human', now()->subMinutes(119 + $i)->toDateTimeString());
        $rapidas[] = $c;
    }

    $lenta = convParaTiempos();
    mensaje($lenta, 'inbound', 'contact', now()->subMinutes(620)->toDateTimeString());
    mensaje($lenta, 'outbound', 'human', now()->subMinutes(20)->toDateTimeString());

    $d = (new ResponseTimeReport(now()->subDay(), now()->addDay()))
        ->soloConversaciones(array_merge($rapidas, [$lenta]))
        ->build()['asesor'];

    expect($d['respuestas'])->toBe(10)
        // La mediana aguanta el valor extremo; el promedio no.
        ->and($d['mediana'])->toBe(1.0)
        ->and($d['promedio'])->toBeGreaterThan(50.0)
        ->and($d['p90'])->toBe(600.0);
});

it('el endpoint del tablero lo devuelve con las dos ramas', function () {
    $r = test()->getJson(route('admin.dashboard.stats', ['type' => 'tiempo-respuesta-whatsapp']))
        ->assertSuccessful()
        ->json('statistics');

    expect($r)->toHaveKeys(['agente', 'asesor', 'por_asesor'])
        ->and($r['agente'])->toHaveKeys(['respuestas', 'mediana', 'p90', 'promedio']);
});

// ---------------------------------------------------------------------------
// Filtro por ciudad, como el resto del tablero
// ---------------------------------------------------------------------------

it('acota el reporte a una ciudad', function () {
    $tarija = (int) DB::table('lead_pipelines')->where('name', 'Tarija')->value('id');

    $todas = (new ResponseTimeReport(now()->subDays(30), now()))->build();
    $sola = (new ResponseTimeReport(now()->subDays(30), now()))->enPipeline($tarija)->build();

    expect($sola['agente']['respuestas'])->toBeLessThan($todas['agente']['respuestas'])
        ->and($sola['agente']['respuestas'])->toBeGreaterThan(0);
});

it('sin ciudad devuelve todo, como antes', function () {
    $a = (new ResponseTimeReport(now()->subDays(30), now()))->build();
    $b = (new ResponseTimeReport(now()->subDays(30), now()))->enPipeline(null)->build();

    expect($b['agente']['respuestas'])->toBe($a['agente']['respuestas']);
});

it('el endpoint del tablero respeta pipeline_id', function () {
    $tarija = (int) DB::table('lead_pipelines')->where('name', 'Tarija')->value('id');

    $todas = test()->getJson(route('admin.dashboard.stats', ['type' => 'tiempo-respuesta-whatsapp']))
        ->json('statistics.agente.respuestas');

    $sola = test()->getJson(route('admin.dashboard.stats', [
        'type' => 'tiempo-respuesta-whatsapp', 'pipeline_id' => $tarija,
    ]))->json('statistics.agente.respuestas');

    expect($sola)->toBeLessThan($todas);
});
