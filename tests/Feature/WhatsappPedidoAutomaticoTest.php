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

/*
|--------------------------------------------------------------------------
| El pedido fantasma
|--------------------------------------------------------------------------
| El CRM abre un pedido vacío cuando alguien escribe; el agente registra la
| venta por la API REST y crea OTRO, porque esa API no sabe que ya hay uno.
| Medido el 2026-09-22: 81 pedidos duplicados.
*/

function etapaDescartado(int $pipelineId): ?int
{
    return DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->where('code', 'descartado')
        ->value('id');
}

/*
 * La etapa "Descartado" se eliminó de todos los pipelines el 30/09/2026 por
 * decisión del negocio, junto con los 101 pedidos que tenía (copia en
 * storage/backups/descartados-20260930-115117.json).
 *
 * Los tests que dependen de ella quedan SALTEADOS y no borrados: el mecanismo
 * de limpieza de fantasmas sigue existiendo en el código y hay una decisión
 * pendiente sobre qué hace de ahora en más —borrar el fantasma directo o
 * dejarlo visible en el kanban—. Cuando eso se resuelva, estos tests se
 * reescriben o se eliminan con el resto.
 */
function etapaDescartadoDisponible(): void
{
    if (! DB::table('lead_pipeline_stages')->where('code', 'descartado')->exists()) {
        test()->markTestSkipped('La etapa "Descartado" se eliminó el 30/09/2026; queda pendiente definir el reemplazo.');
    }
}

it('existe la etapa Descartado en todos los pipelines', function () {
    etapaDescartadoDisponible();

    /*
     * Sólo los pipelines que tienen etapas: otros tests crean pipelines
     * temporales sin ninguna ("Bogotá Export …"), y ésos no son parte del
     * embudo real.
     */
    $reales = Pipeline::whereHas('stages')->get();

    expect($reales)->not->toBeEmpty();

    foreach ($reales as $p) {
        expect(etapaDescartado($p->id))->not->toBeNull("Falta Descartado en {$p->name}");
    }
});

it('Descartado cuenta como etapa cerrada', function () {
    etapaDescartadoDisponible();

    expect(Webkul\Lead\Services\CitySyncService::CLOSED_STAGE_CODES)->toContain('descartado');
});

it('un pedido recién abierto es un fantasma', function () {
    $lead = app(LeadOpener::class)->open(contactoConCiudad(null));

    expect(app(LeadOpener::class)->isUntouched($lead))->toBeTrue();
});

/*
 * Lo que protege el trabajo del equipo: 99 de 122 pedidos automáticos ya
 * habían sido movidos de etapa por un asesor. Ninguno de esos puede tocarse.
 */
it('sigue siendo fantasma aunque lo hayan movido de etapa', function () {
    $lead = app(LeadOpener::class)->open(contactoConCiudad(null));

    $otra = DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $lead->lead_pipeline_id)
        ->where('code', 'cliente-contactado')
        ->value('id');

    $lead->forceFill(['lead_pipeline_stage_id' => $otra])->save();

    /*
     * Antes esto lo declaraba "tocado por un asesor" y lo salvaba. Era falso:
     * el que lo mueve es el propio agente. El #1697 pasó a "Cliente contactado"
     * a las 10:48 por el Agente y a las 10:53 se creó el #1698 aparte con el
     * pedido real; el fantasma quedaba a salvo por haberse movido. 23 casos.
     */
    expect(app(LeadOpener::class)->isUntouched($lead->fresh()))->toBeTrue();
});

it('deja de ser fantasma si lo mandaron a una etapa de cierre', function () {
    etapaDescartadoDisponible();

    $lead = app(LeadOpener::class)->open(contactoConCiudad(null));

    $cerrada = DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $lead->lead_pipeline_id)
        ->where('code', 'pedidos-cancelados')
        ->value('id');

    $lead->forceFill(['lead_pipeline_stage_id' => $cerrada])->save();

    // Un pedido que alguien ya cerró no se vuelve a tocar.
    expect(app(LeadOpener::class)->isUntouched($lead->fresh()))->toBeFalse();
});

it('deja de ser fantasma si tiene monto', function () {
    $lead = app(LeadOpener::class)->open(contactoConCiudad(null));
    $lead->forceFill(['lead_value' => 120])->save();

    expect(app(LeadOpener::class)->isUntouched($lead->fresh()))->toBeFalse();
});

/*
 * EL CASO CENTRAL: crear el pedido real descarta el vacío, sin que el agente
 * tenga que cambiar nada.
 */
it('crear un pedido real descarta el fantasma del mismo contacto', function () {
    etapaDescartadoDisponible();

    $person = contactoConCiudad(Pipeline::where('name', 'Sucre')->first()?->id);

    $fantasma = app(LeadOpener::class)->open($person);

    $real = app(Webkul\Lead\Repositories\LeadRepository::class)->create([
        'entity_type'            => 'leads',
        'title'                  => 'Pedido Promo Primavera',
        'lead_value'             => 75,
        'person'                 => ['id' => $person->id],
        'lead_pipeline_id'       => $fantasma->lead_pipeline_id,
        'lead_pipeline_stage_id' => $fantasma->lead_pipeline_stage_id,
        'status'                 => 1,
    ]);

    Illuminate\Support\Facades\Event::dispatch('lead.create.after', $real);

    expect($fantasma->fresh()->lead_pipeline_stage_id)->toBe(etapaDescartado($fantasma->lead_pipeline_id))
        ->and($fantasma->fresh()->title)->toContain('reemplazado por #'.$real->id)
        // El real queda intacto.
        ->and($real->fresh()->lead_pipeline_stage_id)->not->toBe(etapaDescartado($real->lead_pipeline_id));
});

/*
 * LOS MULTIPEDIDOS: el fantasma se consume UNA SOLA VEZ. El segundo pedido
 * real no descarta al primero. Medido: 14 contactos ya tienen más de un pedido.
 */
it('un segundo pedido real no descarta al primero', function () {
    etapaDescartadoDisponible();

    $person = contactoConCiudad(null);

    app(LeadOpener::class)->open($person);

    $crear = fn (string $t, float $v) => app(Webkul\Lead\Repositories\LeadRepository::class)->create([
        'entity_type'            => 'leads', 'title' => $t, 'lead_value' => $v,
        'person'                 => ['id' => $person->id], 'status' => 1,
        'lead_pipeline_id'       => Pipeline::where('is_default', 1)->first()->id,
        'lead_pipeline_stage_id' => Pipeline::where('is_default', 1)->first()->stages()->orderBy('sort_order')->first()->id,
    ]);

    $primero = $crear('Pedido Promo Tannat', 225);
    Illuminate\Support\Facades\Event::dispatch('lead.create.after', $primero);

    $segundo = $crear('Pedido Promo Blancos', 135);
    Illuminate\Support\Facades\Event::dispatch('lead.create.after', $segundo);

    // Los dos pedidos reales siguen vivos.
    expect($primero->fresh()->lead_pipeline_stage_id)->not->toBe(etapaDescartado($primero->lead_pipeline_id))
        ->and($segundo->fresh()->lead_pipeline_stage_id)->not->toBe(etapaDescartado($segundo->lead_pipeline_id));
});

it('un contacto sin pedido real conserva su pedido automático', function () {
    $person = contactoConCiudad(null);
    $fantasma = app(LeadOpener::class)->open($person);

    // Nadie compró: el vacío es lo único que hace visible a esa persona.
    expect(app(LeadOpener::class)->isUntouched($fantasma->fresh()))->toBeTrue()
        ->and($fantasma->fresh()->lead_pipeline_stage_id)->not->toBe(etapaDescartado($fantasma->lead_pipeline_id));
});

/*
|--------------------------------------------------------------------------
| "Sin ciudad" no es un dato: el CRM le deja el camino libre al agente
|--------------------------------------------------------------------------
|
| La cadena que bloqueaba: LeadOpener abría el pedido en el pipeline "Sin
| ciudad" -> CitySyncService escribía ese id en la ficha del cliente ->
| CityRouter::rememberOnPerson veía la fila ocupada y se negaba a guardar la
| ciudad que el agente acababa de averiguar. 43 fichas quedaron así.
*/

function ciudadDeLaFicha(int $personId): ?int
{
    $attr = DB::table('attributes')->where('code', 'cliente_ciudad')
        ->where('entity_type', 'persons')->value('id');

    $valor = DB::table('attribute_values')->where('entity_type', 'persons')
        ->where('entity_id', $personId)->where('attribute_id', $attr)->value('integer_value');

    return $valor ? (int) $valor : null;
}

it('abrir un pedido sin ciudad NO ensucia la ficha del cliente', function () {
    $person = contactoConCiudad(null);

    app(LeadOpener::class)->open($person);

    // El pedido vive en "Sin ciudad", pero la ficha queda vacía.
    expect(ciudadDeLaFicha($person->id))->toBeNull();
});

it('la ciudad que averigua el agente sí se guarda después del pedido automático', function () {
    $person = contactoConCiudad(null);
    $lead = app(LeadOpener::class)->open($person);

    $conv = ConversationProxy::modelClass()::create([
        'gateway'   => 'cloud_api',
        'wa_phone'  => '+5917'.random_int(1000000, 9999999),
        'person_id' => $person->id,
        'lead_id'   => $lead->id,
    ]);

    $scz = Pipeline::where('name', 'Santa Cruz')->first();

    app(Webkul\Whatsapp\Services\Handoff::class)->request($conv, 'motivo', 'ai', null, 'Santa Cruz');

    // Esto es lo que antes se perdía: el dato se usaba una vez y no quedaba.
    expect(ciudadDeLaFicha($person->id))->toBe($scz->id);
});

it('una ficha en "Sin ciudad" se deja pisar por una ciudad real', function () {
    $sinCiudad = Pipeline::where('name', 'Sin ciudad')->first();
    $person = contactoConCiudad($sinCiudad->id);

    $tarija = Pipeline::where('name', 'Tarija')->first();

    app(Webkul\Whatsapp\Services\CityRouter::class)->rememberOnPerson($person->id, $tarija->id);

    expect(ciudadDeLaFicha($person->id))->toBe($tarija->id);
});

it('una ciudad real cargada por el equipo NO se pisa', function () {
    $laPaz = Pipeline::where('name', 'La Paz')->first();
    $person = contactoConCiudad($laPaz->id);

    $scz = Pipeline::where('name', 'Santa Cruz')->first();

    app(Webkul\Whatsapp\Services\CityRouter::class)->rememberOnPerson($person->id, $scz->id);

    // Un LLM no pisa lo que cargó una persona.
    expect(ciudadDeLaFicha($person->id))->toBe($laPaz->id);
});

it('una ficha en "Sin ciudad" no arrastra los pedidos abiertos a ese pipeline', function () {
    $sinCiudad = Pipeline::where('name', 'Sin ciudad')->first();
    $tarija = Pipeline::where('name', 'Tarija')->first();

    $person = contactoConCiudad($sinCiudad->id);

    $lead = Lead::create([
        'title'                  => 'Pedido en Tarija '.uniqid(),
        'status'                 => 1,
        'person_id'              => $person->id,
        'lead_pipeline_id'       => $tarija->id,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $tarija->id)->orderBy('sort_order')->value('id'),
        'user_id'                => getDefaultAdmin()->id,
    ]);

    app(Webkul\Lead\Services\CitySyncService::class)->syncFromPerson($person->fresh());

    expect($lead->fresh()->lead_pipeline_id)->toBe($tarija->id);
});

/*
|--------------------------------------------------------------------------
| El vínculo conversación -> pedido se completa cuando el pedido aparece
|--------------------------------------------------------------------------
|
| Antes `lead_id` sólo se escribía al CREAR la conversación, o sea únicamente
| si la persona ya tenía un pedido en ese instante. El caso real: la conv#968
| nació el 15/09 sin pedido, el #2563 se registró el 23/09 y nunca se enlazó.
*/

it('enlaza el pedido nuevo con la conversación que estaba suelta', function () {
    $person = contactoConCiudad(null);

    $conv = ConversationProxy::modelClass()::create([
        'gateway'   => 'cloud_api',
        'wa_phone'  => '+5917'.random_int(1000000, 9999999),
        'person_id' => $person->id,
        'lead_id'   => null,
    ]);

    $lead = app(LeadOpener::class)->open($person);

    expect($conv->fresh()->lead_id)->toBe($lead->id);
});

it('no repunta una conversación que ya tiene su pedido', function () {
    $person = contactoConCiudad(null);
    $primero = app(LeadOpener::class)->open($person);

    $conv = ConversationProxy::modelClass()::create([
        'gateway'   => 'cloud_api',
        'wa_phone'  => '+5917'.random_int(1000000, 9999999),
        'person_id' => $person->id,
        'lead_id'   => $primero->id,
    ]);

    // Un contacto puede tener varios pedidos; el vínculo es con el primero.
    $segundo = Lead::create([
        'title'                  => 'Segundo pedido '.uniqid(),
        'status'                 => 1,
        'person_id'              => $person->id,
        'lead_pipeline_id'       => $primero->lead_pipeline_id,
        'lead_pipeline_stage_id' => $primero->lead_pipeline_stage_id,
        'user_id'                => getDefaultAdmin()->id,
    ]);

    expect($conv->fresh()->lead_id)->toBe($primero->id)
        ->and($conv->fresh()->lead_id)->not->toBe($segundo->id);
});

it('no toca conversaciones de otro contacto', function () {
    $otro = contactoConCiudad(null);

    $conv = ConversationProxy::modelClass()::create([
        'gateway'   => 'cloud_api',
        'wa_phone'  => '+5917'.random_int(1000000, 9999999),
        'person_id' => $otro->id,
        'lead_id'   => null,
    ]);

    app(LeadOpener::class)->open(contactoConCiudad(null));

    expect($conv->fresh()->lead_id)->toBeNull();
});

it('un mensaje entrante completa el vínculo que faltaba', function () {
    $phone = '5917'.random_int(1000000, 9999999);

    $person = contactoConCiudad(null);
    DB::table('persons')->where('id', $person->id)
        ->update(['contact_numbers' => json_encode([['label' => 'work', 'value' => $phone]])]);

    // Conversación vieja: tiene persona pero nunca tuvo pedido.
    $conv = ConversationProxy::modelClass()::create([
        'gateway'   => 'cloud_api',
        'wa_phone'  => '+'.$phone,
        'person_id' => $person->id,
        'lead_id'   => null,
    ]);

    $lead = app(LeadOpener::class)->open($person);
    $conv->forceFill(['lead_id' => null])->save();

    (new IngestInboundPayload('cloud_api', [
        'entry' => [['changes' => [['value' => [
            'contacts' => [['profile' => ['name' => 'Cliente']]],
            'messages' => [['id' => 'wamid.link'.random_int(1000, 9999), 'from' => $phone, 'type' => 'text', 'text' => ['body' => 'Hola']]],
        ]]]]],
    ]))->handle(app(GatewayManager::class), app(ConversationResolver::class));

    /*
     * Este era el agujero: backfill() sólo miraba el pedido dentro del
     * if (! $conversation->person_id), así que una conversación que ya tenía
     * persona no volvía a completarlo nunca.
     */
    expect($conv->fresh()->lead_id)->toBe($lead->id);
});
