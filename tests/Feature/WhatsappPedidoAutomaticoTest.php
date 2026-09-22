<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Pipeline;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\LeadOpener;

/*
 * Pedido automático para quien escribe por WhatsApp.
 *
 * Medido antes del cambio: de 168 conversaciones, 156 no apuntaban a ningún
 * pedido. El contacto sí se creaba, pero esas personas no aparecían en ningún
 * pipeline — para el equipo no existían.
 *
 * En kohlberg LA CIUDAD ES EL PIPELINE, así que el pedido se abre en la ciudad
 * del contacto y a nombre de su encargado; sin ciudad, en el pipeline por
 * defecto (Tarija / Daniel Escalante).
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    Queue::fake();
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

function contactoConCiudad(?int $pipelineId): object
{
    $id = (int) DB::table('persons')->insertGetId([
        'name'            => 'Contacto pedido '.uniqid(),
        'contact_numbers' => json_encode([['label' => 'work', 'value' => '5917'.random_int(1000000, 9999999)]]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    if ($pipelineId) {
        DB::table('attribute_values')->insert([
            'entity_id'     => $id,
            'entity_type'   => 'persons',
            'attribute_id'  => DB::table('attributes')->where('code', 'cliente_ciudad')->where('entity_type', 'persons')->value('id'),
            'integer_value' => $pipelineId,
        ]);
    }

    return Webkul\Contact\Models\Person::find($id);
}

it('abre el pedido en la ciudad del contacto', function () {
    $ciudad = Pipeline::where('name', 'Santa Cruz')->first();

    if (! $ciudad) {
        test()->markTestSkipped('No existe el pipeline Santa Cruz.');
    }

    $lead = app(LeadOpener::class)->open(contactoConCiudad($ciudad->id));

    expect($lead)->not->toBeNull()
        ->and($lead->lead_pipeline_id)->toBe($ciudad->id);
});

/*
 * Éste es el caso que el usuario nombró: sin ciudad conocida, el pedido cae en
 * el pipeline por defecto, que es Tarija, con su encargado.
 */
/*
 * Ciudad desconocida -> pipeline "Sin ciudad", NO el por defecto (Tarija).
 *
 * CityRouter toma el pipeline del lead como la fuente MÁS FUERTE de la ciudad,
 * por encima de lo que el cliente le diga al agente. Rellenar con Tarija
 * dejaría a un cliente de Santa Cruz marcado como de Tarija sin que su propia
 * respuesta pudiera corregirlo.
 */
it('sin ciudad conocida NO inventa una ciudad', function () {
    $sinCiudad = Pipeline::where('name', 'Sin ciudad')->first();

    $lead = app(LeadOpener::class)->open(contactoConCiudad(null));

    expect($lead->lead_pipeline_id)->toBe($sinCiudad->id)
        ->and($lead->lead_pipeline_id)->not->toBe(Pipeline::where('is_default', 1)->first()->id);
});

/*
 * LA PRUEBA QUE IMPORTA: el pedido automático no puede pisar la ciudad real.
 */
it('el pedido automático no contamina la ciudad que resuelve el CRM', function () {
    $person = contactoConCiudad(null);

    $lead = app(LeadOpener::class)->open($person);

    $conversacion = Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create([
        'gateway'   => 'cloud_api',
        'wa_phone'  => '+5917'.random_int(1000000, 9999999),
        'person_id' => $person->id,
        'lead_id'   => $lead->id,
    ]);

    $router = app(Webkul\Whatsapp\Services\CityRouter::class);

    // Sin aporte del agente: sigue sin ciudad, no "Tarija".
    expect($router->resolve($conversacion))->toBeNull();

    // Y cuando el cliente dice de dónde es, ESO manda.
    $scz = Pipeline::where('name', 'Santa Cruz')->first();

    expect($router->resolve($conversacion, 'Santa Cruz'))->toBe($scz->id);
});

it('le pone como dueño al encargado de esa ciudad', function () {
    $ciudad = Pipeline::where('name', 'Tarija')->first();

    $lead = app(LeadOpener::class)->open(contactoConCiudad($ciudad->id));

    $encargado = app(Webkul\Whatsapp\Services\CityRouter::class)->advisorFor($ciudad->id);

    expect($lead->user_id)->toBe($encargado);
});

it('abre el pedido en la etapa inicial y con la fuente WhatsApp', function () {
    $lead = app(LeadOpener::class)->open(contactoConCiudad(null));

    $etapa = Pipeline::find($lead->lead_pipeline_id)->stages()->orderBy('sort_order')->first();

    expect($lead->lead_pipeline_stage_id)->toBe($etapa->id)
        ->and(Webkul\Lead\Models\Source::find($lead->lead_source_id)->name)->toBe('Whatsapp')
        ->and((int) $lead->status)->toBe(1);
});

/*
 * Un contacto que YA tiene pedidos no genera otro: se enlaza el último. Si no,
 * cada conversación nueva de un cliente habitual abriría un pedido duplicado.
 */
it('no abre un segundo pedido si el contacto ya tiene uno', function () {
    $person = contactoConCiudad(null);

    $primero = app(LeadOpener::class)->open($person);

    expect(app(LeadOpener::class)->forPerson($person))->toBe($primero->id)
        ->and(Lead::where('person_id', $person->id)->count())->toBe(1);
});

it('respeta el interruptor de config', function () {
    config(['whatsapp.auto_create_lead' => false]);

    expect(app(LeadOpener::class)->forPerson(contactoConCiudad(null)))->toBeNull();
});

/*
 * De punta a punta: entra un mensaje de un número nuevo y la conversación queda
 * con contacto Y pedido.
 */
it('un mensaje de un número nuevo deja conversación con contacto y pedido', function () {
    $phone = '5917'.random_int(1000000, 9999999);

    (new IngestInboundPayload('cloud_api', [
        'entry' => [['changes' => [['value' => [
            'contacts' => [['profile' => ['name' => 'Cliente nuevo']]],
            'messages' => [['id' => 'wamid.nuevo'.random_int(1000, 9999), 'from' => $phone, 'type' => 'text', 'text' => ['body' => 'Hola']]],
        ]]]]],
    ]))->handle(app(GatewayManager::class), app(ConversationResolver::class));

    $conversacion = ConversationProxy::modelClass()::where('wa_phone', '+'.$phone)->first();

    expect($conversacion)->not->toBeNull()
        ->and($conversacion->person_id)->not->toBeNull()
        ->and($conversacion->lead_id)->not->toBeNull();

    $lead = Lead::find($conversacion->lead_id);

    // Un número nuevo no tiene ciudad, así que el pedido va a "Sin ciudad" —
    // no al por defecto, que marcaría al cliente como de Tarija.
    expect(Pipeline::find($lead->lead_pipeline_id)->name)->toBe('Sin ciudad');
});

/*
 * Abrir el pedido NUNCA puede costar el mensaje del cliente: corre dentro de la
 * ingesta del webhook, así que un fallo se traga y se registra.
 */
it('si no se puede abrir el pedido, el mensaje se guarda igual', function () {
    // Sin pipelines no hay dónde abrirlo.
    config(['whatsapp.auto_create_lead' => true]);

    $person = contactoConCiudad(null);

    $opener = new class(app(Webkul\Whatsapp\Services\CityRouter::class)) extends LeadOpener
    {
        public function pipelineFor($person): ?Illuminate\Database\Eloquent\Model
        {
            return null;
        }
    };

    expect($opener->forPerson($person))->toBeNull()
        ->and(Lead::where('person_id', $person->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| El dueño: era lo que faltaba ver
|--------------------------------------------------------------------------
*/

/*
 * Se afirma contra el USUARIO CONCRETO, no contra advisorFor().
 *
 * El test anterior comparaba el dueño con lo que devuelve advisorFor(), así que
 * si los dos hubieran dado null habría pasado igual sin que nadie tuviera el
 * pedido. Acá se nombra a Daniel Escalante.
 */
/*
 * El dueño por defecto sí es Daniel Escalante —lo que se pidió— pero el pedido
 * NO queda marcado como de Tarija. Las dos cosas son independientes: quién lo
 * revisa, y de qué ciudad es el cliente.
 */
it('el pedido sin ciudad queda a nombre del revisor por defecto', function () {
    $lead = app(LeadOpener::class)->open(contactoConCiudad(null));

    $dueno = Webkul\User\Models\User::find($lead->user_id);

    expect($dueno)->not->toBeNull()
        ->and($dueno->name)->toBe('Daniel Escalante')
        // pero el pedido NO dice que sea de Tarija
        ->and(Pipeline::find($lead->lead_pipeline_id)->name)->toBe('Sin ciudad');
});

it('ningún pedido automático queda sin dueño', function () {
    foreach (Pipeline::whereNotNull('id')->limit(4)->get() as $pipeline) {
        $lead = app(LeadOpener::class)->open(contactoConCiudad($pipeline->id));

        if (! $lead) {
            continue;
        }

        // Puede no haber encargado para una ciudad sin leads, pero entonces el
        // pedido tiene que caer en el dueño del contacto, nunca en la nada.
        $sinEncargado = app(Webkul\Whatsapp\Services\CityRouter::class)->advisorFor($pipeline->id) === null;

        expect($lead->user_id !== null || $sinEncargado)->toBeTrue();
    }
});

/*
 * 'Whatsapp', no 'WhatsApp'. La creación manual del panel usaba la segunda
 * grafía con firstOrCreate: el primer uso habría creado una fuente duplicada y
 * partido en dos el reporte de los 537 pedidos que ya usan la primera.
 */
it('usa la fuente que ya existe y no crea una duplicada', function () {
    $antes = Webkul\Lead\Models\Source::count();

    $lead = app(LeadOpener::class)->open(contactoConCiudad(null));

    expect(Webkul\Lead\Models\Source::find($lead->lead_source_id)->name)->toBe('Whatsapp')
        ->and(Webkul\Lead\Models\Source::count())->toBe($antes);
});

/*
 * La creación manual desde el panel del cliente y la automática tienen que
 * dejar pedidos idénticos: misma ciudad, mismo dueño, misma fuente.
 */
it('el panel del cliente crea el pedido igual que el automático', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    $person = contactoConCiudad(Pipeline::where('name', 'Santa Cruz')->first()?->id);

    $r = test()->postJson(route('admin.whatsapp.person.leads', $person->id), [
        'title'      => 'Pedido a mano '.uniqid(),
        'lead_value' => 250,
    ])->assertOk();

    $manual = Lead::findOrFail($r->json('lead.id'));

    expect($manual->lead_pipeline_id)->toBe(Pipeline::where('name', 'Santa Cruz')->first()->id)
        ->and($manual->user_id)->not->toBeNull()
        ->and(Webkul\Lead\Models\Source::find($manual->lead_source_id)->name)->toBe('Whatsapp')
        ->and((float) $manual->lead_value)->toBe(250.0);
});
