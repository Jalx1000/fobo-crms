<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Exceptions\HandoffConflictException;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\CityRouter;
use Webkul\Whatsapp\Services\Handoff;

/**
 * Derivación a un asesor, ruteada POR CIUDAD.
 *
 * En kohlberg la ciudad es el pipeline y cada una tiene un Encargado. Medido
 * sobre las conversaciones reales: solo 2 de 26 tienen lead y 9 de 26 tienen la
 * ciudad en la ficha del cliente, así que la cascada de resolución no es un
 * lujo — sin ella la mayoría de las derivaciones no tendría a quién ir.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
    Mail::fake();
});

function ciudadId(string $nombre): int
{
    return (int) DB::table('lead_pipelines')->where('name', $nombre)->value('id');
}

function convHandoff(array $attrs = []): Conversation
{
    return ConversationProxy::modelClass()::create(array_merge([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '5917'.random_int(1000000, 9999999),
        'wa_name'    => 'Cliente handoff',
        'status'     => 'open',
        'ai_enabled' => true,
    ], $attrs));
}

/** Persona nueva con (o sin) ciudad cargada. */
function personaCon(?int $cityId): int
{
    $id = (int) DB::table('persons')->insertGetId([
        'name'            => 'Persona handoff '.uniqid(),
        'contact_numbers' => json_encode([['label' => 'work', 'value' => '5917'.random_int(1000000, 9999999)]]),
        'created_at'      => now(), 'updated_at' => now(),
    ]);

    if ($cityId) {
        DB::table('attribute_values')->insert([
            'entity_id'     => $id, 'entity_type' => 'persons',
            'attribute_id'  => DB::table('attributes')->where('code', 'cliente_ciudad')->value('id'),
            'integer_value' => $cityId,
        ]);
    }

    return $id;
}

// ---------------------------------------------------------------------------
// Ruteo por ciudad
// ---------------------------------------------------------------------------

it('rutea al asesor de la ciudad del cliente', function () {
    $santaCruz = ciudadId('Santa Cruz');
    $conv = convHandoff(['person_id' => personaCon($santaCruz)]);

    $r = app(Handoff::class)->request($conv, 'Quiere hablar con alguien');

    $conv = $r['conversation'];

    expect($r['changed'])->toBeTrue()
        ->and($conv->handoff_city_id)->toBe($santaCruz)
        ->and($conv->handoff_state)->toBe(Conversation::HANDOFF_ASSIGNED)
        ->and($conv->assigned_user_id)->not->toBeNull();

    // Y quien quedó asignado atiende esa ciudad.
    expect(app(CityRouter::class)->advisorFor($santaCruz))->toBe($conv->assigned_user_id);
});

it('usa la ciudad que averiguó el agente cuando el crm no la tiene', function () {
    $conv = convHandoff(['person_id' => personaCon(null)]);

    $r = app(Handoff::class)->request($conv, 'Pide un asesor', 'ai', null, 'potosi');

    expect($r['conversation']->handoff_city_id)->toBe(ciudadId('Potosí'))
        ->and($r['conversation']->assigned_user_id)->not->toBeNull();
});

it('no deja que el agente pise la ciudad que ya tiene el crm', function () {
    $laPaz = ciudadId('La Paz');
    $conv = convHandoff(['person_id' => personaCon($laPaz)]);

    // El agente dice otra cosa; el dato cargado por el equipo manda.
    $r = app(Handoff::class)->request($conv, 'motivo', 'ai', null, 'Cochabamba');

    expect($r['conversation']->handoff_city_id)->toBe($laPaz);
});

it('aprende la ciudad que aportó el agente y la guarda en el cliente', function () {
    $personId = personaCon(null);
    $conv = convHandoff(['person_id' => $personId]);

    app(Handoff::class)->request($conv, 'motivo', 'ai', null, 'Sucre');

    $guardada = DB::table('attribute_values')
        ->where('entity_type', 'persons')->where('entity_id', $personId)
        ->where('attribute_id', DB::table('attributes')->where('code', 'cliente_ciudad')->value('id'))
        ->value('integer_value');

    expect((int) $guardada)->toBe(ciudadId('Sucre'));
});

it('deja la conversación en el pool si no hay ciudad', function () {
    $conv = convHandoff(['person_id' => personaCon(null)]);

    $r = app(Handoff::class)->request($conv, 'no sé de dónde escribe');

    expect($r['conversation']->handoff_state)->toBe(Conversation::HANDOFF_REQUESTED)
        ->and($r['conversation']->assigned_user_id)->toBeNull()
        ->and($r['conversation']->handoffPooled())->toBeTrue();
});

it('no rutea a "Sin ciudad", que es un pipeline pero no una ciudad', function () {
    $conv = convHandoff(['person_id' => personaCon(null)]);

    $r = app(Handoff::class)->request($conv, 'motivo', 'ai', null, 'Sin ciudad');

    expect($r['conversation']->handoff_city_id)->toBeNull()
        ->and($r['conversation']->assigned_user_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Mecánica
// ---------------------------------------------------------------------------

it('apagar el agente ES el handoff', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);

    expect($conv->aiEffective())->toBeTrue();

    $r = app(Handoff::class)->request($conv, 'motivo');

    // Es lo que hace que IngestInboundPayload y NotifyAgent dejen de avisarle.
    expect($r['conversation']->ai_enabled)->toBeFalse()
        ->and($r['conversation']->aiEffective())->toBeFalse();
});

it('es idempotente: dos pedidos, una sola derivación', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);

    $primero = app(Handoff::class)->request($conv, 'primero');
    $segundo = app(Handoff::class)->request($conv->fresh(), 'segundo');

    expect($primero['changed'])->toBeTrue()
        ->and($segundo['changed'])->toBeFalse()
        // El motivo del primero se conserva: el segundo no re-escribe nada.
        ->and($segundo['conversation']->handoff_reason)->toBe('primero');
});

it('dos asesores tomando a la vez: gana exactamente uno', function () {
    $conv = convHandoff(['person_id' => personaCon(null)]); // sin ciudad -> pool
    app(Handoff::class)->request($conv, 'motivo');

    $unoId = (int) DB::table('users')->where('status', 1)->value('id');
    $otroId = (int) DB::table('users')->where('status', 1)->where('id', '!=', $unoId)->value('id');

    $servicio = app(Handoff::class);

    $servicio->claim($conv->fresh(), $unoId);

    expect(fn () => $servicio->claim($conv->fresh(), $otroId))
        ->toThrow(HandoffConflictException::class);

    expect($conv->fresh()->assigned_user_id)->toBe($unoId);
});

it('cerrar la derivación devuelve la conversación al agente', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);
    app(Handoff::class)->request($conv, 'motivo');

    $cerrada = app(Handoff::class)->resolve($conv->fresh());

    expect($cerrada->handoff_state)->toBe(Conversation::HANDOFF_RESOLVED)
        ->and($cerrada->handoffOpen())->toBeFalse()
        ->and($cerrada->ai_enabled)->toBeTrue()
        // Se conserva quién la atendió: es el registro de auditoría.
        ->and($cerrada->assigned_user_id)->not->toBeNull();
});

it('el payload del agente tipa assigned_user como entero, no como objeto', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Santa Cruz'))]);
    $r = app(Handoff::class)->request($conv, 'motivo');

    $payload = app(Handoff::class)->toAgentArray($r['conversation']);

    // El bug que dejó mudo al agente en el tenant de impresión: el CRM mandaba
    // un objeto donde el agente esperaba un int, y pydantic rechazaba el evento
    // entero mientras el CRM seguía respondiendo 200.
    expect($payload['assigned_user'])->toBeInt()
        ->and($payload['assigned_user_name'])->toBeString()
        // Y el inbox sí necesita el objeto.
        ->and(app(Handoff::class)->toArray($r['conversation'])['assigned_user'])->toBeArray();
});

it('expone la ciudad para el banner y el mail', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Cochabamba'))]);
    $r = app(Handoff::class)->request($conv, 'motivo');

    expect(app(Handoff::class)->toArray($r['conversation'])['city'])->toBe('Cochabamba');
});

// ---------------------------------------------------------------------------
// Filtros de la lista: cómo un asesor encuentra lo derivado
// ---------------------------------------------------------------------------

it('el filtro "Míos" no devuelve el pool cuando no hay sesión', function () {
    $conv = convHandoff(['person_id' => personaCon(null)]); // sin ciudad -> pool
    app(Handoff::class)->request($conv, 'motivo');

    $respuesta = app(\Webkul\Whatsapp\Http\Controllers\ChatController::class)
        ->conversations(\Illuminate\Http\Request::create('/', 'GET', ['filter' => 'mine']));

    /*
     * Sin este caso, `where('assigned_user_id', null)` se traduce a `IS NULL` y
     * "Míos" devolvía TODAS las conversaciones del pool: las que justamente no
     * son de nadie.
     */
    expect(json_decode($respuesta->getContent(), true)['data'])->toBeEmpty();
});

it('el filtro "Sin asesor" muestra solo lo que nadie tomó', function () {
    $pool = convHandoff(['person_id' => personaCon(null)]);
    $asignada = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);

    app(Handoff::class)->request($pool, 'sin ciudad');
    app(Handoff::class)->request($asignada, 'con ciudad');

    $respuesta = app(\Webkul\Whatsapp\Http\Controllers\ChatController::class)
        ->conversations(\Illuminate\Http\Request::create('/', 'GET', ['filter' => 'pool']));

    $ids = collect(json_decode($respuesta->getContent(), true)['data'])->pluck('id');

    expect($ids)->toContain($pool->id)
        ->and($ids)->not->toContain($asignada->id);
});

it('las derivaciones suben al tope de la lista', function () {
    $conv = convHandoff(['person_id' => personaCon(null)]);

    // Un mensaje viejo: sin el orden por handoff quedaría sepultada.
    $conv->forceFill(['last_message_at' => now()->subMonths(6)])->save();

    app(Handoff::class)->request($conv, 'espera hace rato');

    $respuesta = app(\Webkul\Whatsapp\Http\Controllers\ChatController::class)
        ->conversations(\Illuminate\Http\Request::create('/', 'GET', ['filter' => 'all']));

    $filas = collect(json_decode($respuesta->getContent(), true)['data']);

    /*
     * No se afirma que sea la PRIMERA fila: la suite corre contra la base de
     * producción, donde puede haber derivaciones reales en el pool más
     * recientes que esta conversación de prueba —y es correcto que vayan
     * arriba. Lo que se fija es la regla: una derivación con un mensaje de
     * hace seis meses igual queda por encima de cualquier conversación SIN
     * derivación, que es lo que evita que quede sepultada.
     */
    $posicion = $filas->search(fn ($f) => $f['id'] === $conv->id);

    expect($posicion)->not->toBeFalse('La derivación no apareció en la lista.')
        ->and($filas[$posicion]['handoff']['pooled'])->toBeTrue();

    $primeraSinHandoff = $filas->search(fn ($f) => empty($f['handoff']));

    if ($primeraSinHandoff !== false) {
        expect($posicion)->toBeLessThan($primeraSinHandoff);
    }
});

it('una derivación abierta calla al agente aunque ai_enabled siga en true', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);

    app(Handoff::class)->request($conv, 'motivo');

    // El caso real: la conversación #323 llevaba 12 días asignada con el flag
    // en true, así que el agente contestaba por encima del asesor.
    $conv->fresh()->forceFill(['ai_enabled' => true])->save();

    expect($conv->fresh()->aiEffective())->toBeFalse();
});

it('el agente vuelve cuando se cierra la derivación', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);

    app(Handoff::class)->request($conv, 'motivo');
    app(Handoff::class)->resolve($conv->fresh());

    expect($conv->fresh()->aiEffective())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Cierre automático: la derivación que nadie contestó vuelve al bot
// ---------------------------------------------------------------------------

it('cierra la derivación que lleva más de 24 h sin mensaje del cliente', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);
    app(Handoff::class)->request($conv, 'motivo');

    $conv->fresh()->forceFill(['last_inbound_at' => now()->subHours(30)])->save();

    test()->artisan('whatsapp:cerrar-derivaciones-inactivas')->assertSuccessful();

    $cerrada = $conv->fresh();

    expect($cerrada->handoffOpen())->toBeFalse()
        // Cerrar la derivación ES devolverla al bot.
        ->and($cerrada->aiEffective())->toBeTrue();
});

it('no toca la que sigue viva', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);
    app(Handoff::class)->request($conv, 'motivo');

    $conv->fresh()->forceFill(['last_inbound_at' => now()->subHours(3)])->save();

    test()->artisan('whatsapp:cerrar-derivaciones-inactivas');

    expect($conv->fresh()->handoffOpen())->toBeTrue();
});

it('cuenta desde el último mensaje del cliente, no desde la derivación', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);
    app(Handoff::class)->request($conv, 'derivada hace rato');

    // Derivación vieja, pero el cliente escribió recién: sigue viva.
    $conv->fresh()->forceFill([
        'handoff_requested_at' => now()->subDays(5),
        'last_inbound_at'      => now()->subMinutes(10),
    ])->save();

    test()->artisan('whatsapp:cerrar-derivaciones-inactivas');

    expect($conv->fresh()->handoffOpen())->toBeTrue();
});

it('con --dry-run no escribe nada', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);
    app(Handoff::class)->request($conv, 'motivo');

    $conv->fresh()->forceFill(['last_inbound_at' => now()->subHours(30)])->save();

    test()->artisan('whatsapp:cerrar-derivaciones-inactivas', ['--dry-run' => true]);

    expect($conv->fresh()->handoffOpen())->toBeTrue();
});

it('deja la auditoría diciendo que fue por inactividad', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);
    app(Handoff::class)->request($conv, 'motivo');

    $conv->fresh()->forceFill(['last_inbound_at' => now()->subHours(30)])->save();

    test()->artisan('whatsapp:cerrar-derivaciones-inactivas');

    $comentario = DB::table('activities')
        ->where('comment', 'like', '%sin respuesta del cliente%')
        ->orderByDesc('id')
        ->value('comment');

    expect($comentario)->toContain('Cerrada automáticamente');
});

it('ignora la conversación que nunca recibió un mensaje', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId('Tarija'))]);
    app(Handoff::class)->request($conv, 'motivo');

    // Sin last_inbound_at no hay desde cuándo contar.
    $conv->fresh()->forceFill(['last_inbound_at' => null])->save();

    test()->artisan('whatsapp:cerrar-derivaciones-inactivas');

    expect($conv->fresh()->handoffOpen())->toBeTrue();
});
