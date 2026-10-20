<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Distribuidor\Services\PreguntaDeCiudad;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * La pregunta de ciudad por WhatsApp y el reparto de los clientes que ya existían.
 *
 * Sin ciudad no hay distribuidor para el cliente. El CRM se la pregunta con una lista
 * antes de que cierre la ventana de 24 h, y cuando contesta le asigna distribuidor.
 *
 * Queue::fake(): ningún mensaje sale de verdad a WhatsApp desde un test.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
    config(['distribuidor.pregunta_ciudad.enabled' => true]);
});

/** Palpalá con un único distribuidor de prueba. */
function preguntaEscenario(): array
{
    $ciudad = (int) DB::table('lead_pipelines')->where('name', 'like', 'Palpal%')->value('id');

    if (! $ciudad) {
        test()->markTestSkipped('No existe la ciudad Palpalá.');
    }

    $rol = Role::where('name', 'Distribuidor')->first() ?? test()->markTestSkipped('Sin rol Distribuidor.');

    $dist = User::create([
        'name'     => 'Distribuidor pregunta '.uniqid(),
        'email'    => 'pregunta-'.uniqid().'@test.local',
        'password' => bcrypt('secret'),
        'role_id'  => $rol->id,
        'status'   => 1,
    ]);

    DB::table('distribuidor_zonas')->where('lead_pipeline_id', $ciudad)->delete();
    DB::table('distribuidor_zonas')->insert([
        'lead_pipeline_id' => $ciudad, 'user_id' => $dist->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['ciudad' => $ciudad, 'dist' => $dist];
}

function preguntaConversacion(array $attrs = []): int
{
    $personId = (int) DB::table('persons')->insertGetId([
        'name' => 'Cliente sin ciudad '.uniqid(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return (int) ConversationProxy::modelClass()::create(array_merge([
        'gateway'         => config('whatsapp.gateway'),
        'wa_phone'        => '+54938'.random_int(10000000, 99999999),
        'person_id'       => $personId,
        'last_inbound_at' => now()->subHours(2),
        'last_message_at' => now()->subHours(2),
    ], $attrs))->id;
}

/** Un mensaje entrante como lo guarda el webhook, con el payload crudo de Meta. */
function preguntaRespuesta(int $conversationId, ?string $body, ?string $seleccion = null)
{
    $payload = $seleccion
        ? ['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $seleccion, 'title' => 'x']]]
        : ['type' => 'text', 'text' => ['body' => $body]];

    return MessageProxy::modelClass()::create([
        'conversation_id' => $conversationId,
        'direction'       => 'inbound',
        'type'            => $seleccion ? 'interactive' : 'text',
        'body'            => $body,
        'status'          => 'delivered',
        'sender'          => 'contact',
        'payload'         => $payload,
    ]);
}

function preguntaCiudadDe(int $conversationId): ?int
{
    $personId = ConversationProxy::modelClass()::find($conversationId)->person_id;

    $valor = DB::table('attribute_values')
        ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
        ->where('attributes.code', 'cliente_ciudad')
        ->where('attribute_values.entity_type', 'persons')
        ->where('attribute_values.entity_id', $personId)
        ->value('attribute_values.integer_value');

    return $valor ? (int) $valor : null;
}

// ───────────────── a quién se le pregunta ─────────────────

it('pregunta sólo a quien no tiene ciudad, dentro de la ventana y una vez', function () {
    preguntaEscenario();

    $si = preguntaConversacion();
    $recien = preguntaConversacion(['last_inbound_at' => now()->subMinutes(10)]);   // le damos tiempo al agente
    $vencida = preguntaConversacion(['last_inbound_at' => now()->subHours(30)]);    // fuera de las 24 h
    $yaPreguntada = preguntaConversacion(['ciudad_preguntada_at' => now()->subHour()]);

    $pendientes = app(PreguntaDeCiudad::class)->pendientes(500)->all();

    expect($pendientes)->toContain($si)
        ->not->toContain($recien)
        ->not->toContain($vencida)
        ->not->toContain($yaPreguntada);
});

it('no le pregunta a quien ya tiene ciudad', function () {
    ['ciudad' => $c] = preguntaEscenario();

    $conv = preguntaConversacion(['handoff_city_id' => $c]);

    expect(app(PreguntaDeCiudad::class)->pendientes(500)->all())->not->toContain($conv);
});

it('manda una lista con las ciudades que tienen distribuidor y no repite', function () {
    ['ciudad' => $c] = preguntaEscenario();

    $conv = preguntaConversacion();
    $pregunta = app(PreguntaDeCiudad::class);

    expect($pregunta->preguntar($conv))->toBeTrue()
        ->and($pregunta->preguntar($conv))->toBeFalse();   // la segunda vez no sale nada

    $mensaje = MessageProxy::modelClass()::where('conversation_id', $conv)->sole();
    $ids = array_column($mensaje->payload['interactive']['options'], 'id');

    expect($mensaje->type)->toBe('interactive')
        ->and($ids)->toContain(PreguntaDeCiudad::PREFIJO.$c)
        ->and(ConversationProxy::modelClass()::find($conv)->ciudad_preguntada_at)->not->toBeNull();

    Queue::assertPushed(SendMessage::class, 1);
});

// ───────────────── la respuesta ─────────────────

it('al tocar una ciudad la guarda en la ficha', function () {
    ['ciudad' => $c] = preguntaEscenario();

    $conv = preguntaConversacion();
    app(PreguntaDeCiudad::class)->preguntar($conv);

    event('whatsapp.message.received', preguntaRespuesta($conv, 'Palpalá', PreguntaDeCiudad::PREFIJO.$c));

    $personId = ConversationProxy::modelClass()::find($conv)->person_id;

    // La ciudad queda en la ficha. El cliente no pasa a ningún distribuidor: sin dueño,
    // sus pedidos los recibe el responsable, que con la ciudad ya sabe a quién dárselos.
    expect(preguntaCiudadDe($conv))->toBe($c)
        ->and(DB::table('persons')->where('id', $personId)->value('user_id'))->toBeNull();
});

it('también entiende la ciudad escrita a mano', function () {
    ['ciudad' => $c] = preguntaEscenario();

    $conv = preguntaConversacion();
    app(PreguntaDeCiudad::class)->preguntar($conv);

    event('whatsapp.message.received', preguntaRespuesta($conv, 'soy de palpala'));

    expect(preguntaCiudadDe($conv))->toBe($c);
});

it('un mensaje que no nombra una ciudad no cambia nada', function () {
    preguntaEscenario();

    $conv = preguntaConversacion();
    app(PreguntaDeCiudad::class)->preguntar($conv);

    event('whatsapp.message.received', preguntaRespuesta($conv, 'hola, quiero agua'));

    expect(preguntaCiudadDe($conv))->toBeNull();
});

it('si no se le preguntó, la respuesta se ignora', function () {
    ['ciudad' => $c] = preguntaEscenario();

    $conv = preguntaConversacion();

    event('whatsapp.message.received', preguntaRespuesta($conv, 'Palpalá', PreguntaDeCiudad::PREFIJO.$c));

    expect(preguntaCiudadDe($conv))->toBeNull();
});

it('no acepta una ciudad que no se le ofreció', function () {
    preguntaEscenario();

    $conv = preguntaConversacion();
    app(PreguntaDeCiudad::class)->preguntar($conv);

    // Un id inventado: no está en la lista, no se guarda.
    event('whatsapp.message.received', preguntaRespuesta($conv, 'x', PreguntaDeCiudad::PREFIJO.'999999'));

    expect(preguntaCiudadDe($conv))->toBeNull();
});

// ───────────────── el reparto de los clientes existentes ─────────────────

it('el reparto en modo prueba no cambia nada', function () {
    ['ciudad' => $c] = preguntaEscenario();

    $personId = (int) DB::table('persons')->insertGetId(['name' => 'Viejo '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('leads')->insert([
        'title'                  => 'Pedido viejo', 'person_id' => $personId, 'lead_pipeline_id' => $c,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $c)->value('id'),
        'created_at'             => now(), 'updated_at' => now(),
    ]);

    test()->artisan('distribuidor:repartir')->assertSuccessful();

    expect(DB::table('persons')->where('id', $personId)->value('user_id'))->toBeNull();
});

it('el reparto aplicado respeta al distribuidor que ya tiene los pedidos del cliente', function () {
    ['ciudad' => $c, 'dist' => $dist] = preguntaEscenario();

    $personId = (int) DB::table('persons')->insertGetId(['name' => 'Viejo '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('leads')->insert([
        'title'                  => 'Pedido viejo', 'person_id' => $personId, 'lead_pipeline_id' => $c, 'user_id' => $dist->id,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $c)->value('id'),
        'created_at'             => now(), 'updated_at' => now(),
    ]);

    test()->artisan('distribuidor:repartir', ['--aplicar' => true, '--ciudad' => $c])->assertSuccessful();

    expect((int) DB::table('persons')->where('id', $personId)->value('user_id'))->toBe($dist->id);
});

/** Un cliente de la ciudad que sólo escribió por WhatsApp: sin pedidos ni dueño. */
function preguntaClienteSoloWhatsapp(int $ciudad): int
{
    $conv = preguntaConversacion();
    $personId = (int) DB::table('whatsapp_conversations')->where('id', $conv)->value('person_id');

    DB::table('attribute_values')->insert([
        'entity_type'   => 'persons',
        'entity_id'     => $personId,
        'attribute_id'  => DB::table('attributes')->where('code', 'cliente_ciudad')->where('entity_type', 'persons')->value('id'),
        'integer_value' => $ciudad,
    ]);

    return $personId;
}

it('en una ciudad con un solo distribuidor, el cliente sin dueño pasa a ser suyo aunque no tenga pedidos', function () {
    ['ciudad' => $c, 'dist' => $dist] = preguntaEscenario();
    $personId = preguntaClienteSoloWhatsapp($c);

    test()->artisan('distribuidor:repartir', ['--aplicar' => true, '--ciudad' => $c])->assertSuccessful();

    expect((int) DB::table('persons')->where('id', $personId)->value('user_id'))->toBe($dist->id);
});

it('con dos distribuidores en la ciudad, el cliente sin dueño no se le da a ninguno', function () {
    ['ciudad' => $c] = preguntaEscenario();

    $otro = User::create([
        'name'     => 'Otro distribuidor '.uniqid(), 'email' => 'otro-'.uniqid().'@test.local',
        'password' => bcrypt('secret'), 'role_id' => Role::where('name', 'Distribuidor')->value('id'), 'status' => 1,
    ]);
    DB::table('distribuidor_zonas')->insert(['lead_pipeline_id' => $c, 'user_id' => $otro->id, 'created_at' => now(), 'updated_at' => now()]);

    $personId = preguntaClienteSoloWhatsapp($c);

    test()->artisan('distribuidor:repartir', ['--aplicar' => true, '--ciudad' => $c])->assertSuccessful();

    expect(DB::table('persons')->where('id', $personId)->value('user_id'))->toBeNull();
});

it('el desplegable de ciudades de la bandeja le cuenta al distribuidor sólo lo suyo', function () {
    ['ciudad' => $c, 'dist' => $dist] = preguntaEscenario();

    $mio = preguntaClienteSoloWhatsapp($c);
    DB::table('persons')->where('id', $mio)->update(['user_id' => $dist->id]);
    preguntaClienteSoloWhatsapp($c);   // de la ciudad, sin dueño
    preguntaConversacion();             // sin ciudad

    $respuesta = test()->actingAs($dist, 'user')->getJson(route('admin.whatsapp.cities'))->assertOk();

    expect($respuesta->json('total'))->toBe(1)
        ->and($respuesta->json('none.count'))->toBe(0);
});
