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

/**
 * Las ciudades reales de ESTA instalación, en orden estable.
 *
 * Antes cada test nombraba una ciudad de kohlberg ('Santa Cruz', 'Tarija'…).
 * Sobre Sensia —cuyas ciudades son Palpalá, Barrio Alto Comedero y San Salvador
 * de Jujuy— `ciudadId()` devolvía 0 y ocho tests fallaban por datos, no por el
 * comportamiento que verifican. Ocho fallas permanentes dejan de leerse y
 * terminan tapando una regresión de verdad.
 *
 * A estos tests no les importa QUÉ ciudad: les importa "una ciudad" y "otra
 * distinta". Así que se toman del pipeline real, salteando los que la config
 * declara que no son ciudades.
 *
 * @return array<int, array{id:int, name:string}>
 */
function ciudades(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $excluidas = (array) config('whatsapp.handoff.exclude_cities', []);

    return $cache = DB::table('lead_pipelines')
        ->orderBy('id')
        ->get(['id', 'name'])
        ->reject(fn ($p) => in_array($p->name, $excluidas, true))
        ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name])
        ->values()
        ->all();
}

/**
 * La n-ésima ciudad real. Los tests que necesitan dos ciudades distintas piden
 * índices distintos.
 */
function ciudad(int $indice): array
{
    $ciudades = ciudades();

    if (! isset($ciudades[$indice])) {
        test()->markTestSkipped(
            'Esta instalación tiene '.count($ciudades).' ciudad(es); el test necesita al menos '.($indice + 1).'.'
        );
    }

    return $ciudades[$indice];
}

function ciudadId(int $indice): int
{
    return ciudad($indice)['id'];
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
    $santaCruz = ciudadId(0);
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

    $r = app(Handoff::class)->request($conv, 'Pide un asesor', 'ai', null, mb_strtolower(ciudad(1)['name']));

    expect($r['conversation']->handoff_city_id)->toBe(ciudadId(1))
        ->and($r['conversation']->assigned_user_id)->not->toBeNull();
});

it('no deja que el agente pise la ciudad que ya tiene el crm', function () {
    $laPaz = ciudadId(0);
    $conv = convHandoff(['person_id' => personaCon($laPaz)]);

    // El agente dice otra cosa; el dato cargado por el equipo manda.
    $r = app(Handoff::class)->request($conv, 'motivo', 'ai', null, ciudad(1)['name']);

    expect($r['conversation']->handoff_city_id)->toBe($laPaz);
});

it('aprende la ciudad que aportó el agente y la guarda en el cliente', function () {
    $personId = personaCon(null);
    $conv = convHandoff(['person_id' => $personId]);

    app(Handoff::class)->request($conv, 'motivo', 'ai', null, ciudad(1)['name']);

    $guardada = DB::table('attribute_values')
        ->where('entity_type', 'persons')->where('entity_id', $personId)
        ->where('attribute_id', DB::table('attributes')->where('code', 'cliente_ciudad')->value('id'))
        ->value('integer_value');

    expect((int) $guardada)->toBe(ciudadId(1));
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
    $conv = convHandoff(['person_id' => personaCon(ciudadId(0))]);

    expect($conv->aiEffective())->toBeTrue();

    $r = app(Handoff::class)->request($conv, 'motivo');

    // Es lo que hace que IngestInboundPayload y NotifyAgent dejen de avisarle.
    expect($r['conversation']->ai_enabled)->toBeFalse()
        ->and($r['conversation']->aiEffective())->toBeFalse();
});

it('es idempotente: dos pedidos, una sola derivación', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId(0))]);

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
    $conv = convHandoff(['person_id' => personaCon(ciudadId(0))]);
    app(Handoff::class)->request($conv, 'motivo');

    $cerrada = app(Handoff::class)->resolve($conv->fresh());

    expect($cerrada->handoff_state)->toBe(Conversation::HANDOFF_RESOLVED)
        ->and($cerrada->handoffOpen())->toBeFalse()
        ->and($cerrada->ai_enabled)->toBeTrue()
        // Se conserva quién la atendió: es el registro de auditoría.
        ->and($cerrada->assigned_user_id)->not->toBeNull();
});

it('el payload del agente tipa assigned_user como entero, no como objeto', function () {
    $conv = convHandoff(['person_id' => personaCon(ciudadId(0))]);
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
    $conv = convHandoff(['person_id' => personaCon(ciudadId(1))]);
    $r = app(Handoff::class)->request($conv, 'motivo');

    expect(app(Handoff::class)->toArray($r['conversation'])['city'])->toBe(ciudad(1)['name']);
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
    $asignada = convHandoff(['person_id' => personaCon(ciudadId(0))]);

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

    $primera = json_decode($respuesta->getContent(), true)['data'][0];

    expect($primera['id'])->toBe($conv->id)
        ->and($primera['handoff']['pooled'])->toBeTrue();
});
