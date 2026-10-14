<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Distribuidor\Services\AsignacionDeZona;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Services\CitySyncService;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * La asignación del pedido al distribuidor de su zona, y lo que el agente sabe del
 * cliente antes de hablarle.
 *
 * 🔴 El bug que originó todo: `LeadRepository::create()` asigna el pedido a quien esté
 * autenticado. Cuando lo crea el agente de IA por la API, el autenticado es el DUEÑO
 * DEL TOKEN —un administrador—, así que el pedido quedaba a nombre de un admin y no le
 * aparecía a quien tiene que salir a entregarlo.
 */
uses(DatabaseTransactions::class);

/**
 * La ciudad por nombre, para no depender de ids que cambian entre instalaciones.
 *
 * Con sufijo propio: las funciones que se declaran en un archivo de Pest son GLOBALES
 * a toda la suite, así que un nombre genérico choca con el de otro test y la corrida
 * entera muere con "Cannot redeclare".
 */
function ciudadDeZona(string $nombre): int
{
    $id = DB::table('lead_pipelines')->where('name', $nombre)->value('id');

    if (! $id) {
        test()->markTestSkipped("No existe la ciudad {$nombre}.");
    }

    return (int) $id;
}

function distribuidorDe(string $ciudad): int
{
    $id = app(AsignacionDeZona::class)->distribuidorDe(ciudadDeZona($ciudad));

    if (! $id) {
        test()->markTestSkipped("La ciudad {$ciudad} no tiene distribuidor cargado.");
    }

    return $id;
}

function etapaDe(int $pipelineId, string $code): int
{
    return (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)->where('code', $code)->value('id');
}

function personaDePrueba(?string $telefono = null): int
{
    return (int) DB::table('persons')->insertGetId([
        'name'            => 'Cliente zona '.uniqid(),
        'contact_numbers' => json_encode($telefono ? [['value' => $telefono, 'label' => 'work']] : []),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

/** Un pedido creado por el camino del agente: autenticado con el token de un admin. */
function pedidoComoElAgente(int $pipelineId, ?int $personId = null, ?int $userId = null)
{
    auth()->guard('user')->loginUsingId(1);

    return app(LeadRepository::class)->create(array_filter([
        'entity_type'            => 'leads',
        'title'                  => 'Pedido '.uniqid(),
        'person_id'              => $personId ?: personaDePrueba(),
        'lead_pipeline_id'       => $pipelineId,
        'lead_pipeline_stage_id' => etapaDe($pipelineId, 'pedidos-confirmado'),
        'lead_source_id'         => 5,
        'lead_type_id'           => 1,
        'lead_value'             => 1000,
        'user_id'                => $userId,
    ], fn ($v) => $v !== null));
}

// ───────────────── la asignación por zona ─────────────────

it('el pedido que crea el agente queda en el distribuidor de la zona, no en el admin del token', function () {
    $palpala = ciudadDeZona('Palpalá');

    $lead = pedidoComoElAgente($palpala);
    $lead->refresh();

    /*
     * Sin esto el pedido quedaba con user_id = 1 (Sofopolis, Administrador), que es el
     * dueño del token `android` con el que entra el agente.
     */
    expect((int) $lead->user_id)->toBe(distribuidorDe('Palpalá'))
        ->and((int) $lead->user_id)->not->toBe(1);
});

it('un pedido de Palpala no queda en el distribuidor de Jujuy', function () {
    $lead = pedidoComoElAgente(ciudadDeZona('Palpalá'));
    $lead->refresh();

    expect((int) $lead->user_id)->not->toBe(distribuidorDe('San Salvador de Jujuy'));
});

it('corrige el distribuidor equivocado que mande el agente', function () {
    $palpala = ciudadDeZona('Palpalá');

    // El agente manda al distribuidor de OTRA ciudad: es un id válido, existe, y es
    // distribuidor. Nada salvo la zona dice que está mal.
    $lead = pedidoComoElAgente($palpala, null, distribuidorDe('San Salvador de Jujuy'));
    $lead->refresh();

    expect((int) $lead->user_id)->toBe(distribuidorDe('Palpalá'));
});

it('mudar el pedido de ciudad lo reasigna', function () {
    $palpala = ciudadDeZona('Palpalá');
    $jujuy = ciudadDeZona('San Salvador de Jujuy');

    $lead = pedidoComoElAgente($palpala);

    DB::table('leads')->where('id', $lead->id)->update(['lead_pipeline_id' => $jujuy]);
    $lead->refresh();
    app(CitySyncService::class)->syncFromLead($lead, $palpala);
    $lead->refresh();

    expect((int) $lead->user_id)->toBe(distribuidorDe('San Salvador de Jujuy'));
});

it('no pisa una asignacion manual al distribuidor correcto', function () {
    $palpala = ciudadDeZona('Palpalá');
    $correcto = distribuidorDe('Palpalá');

    $lead = pedidoComoElAgente($palpala);

    /*
     * Si alguien del equipo eligió a mano al distribuidor que corresponde, esto no lo
     * toca nunca más. Sin esta regla, cada guardado del pedido reescribiría el campo y
     * una corrección manual no duraría.
     */
    DB::table('leads')->where('id', $lead->id)->update(['user_id' => $correcto]);
    $lead->refresh();
    app(CitySyncService::class)->syncFromLead($lead);
    $lead->refresh();

    expect((int) $lead->user_id)->toBe($correcto);
});

it('una ciudad sin distribuidor no deja el pedido en cualquiera', function () {
    $sinCiudad = DB::table('lead_pipelines')->where('name', 'Sin ciudad')->value('id');

    if (! $sinCiudad || app(AsignacionDeZona::class)->distribuidorDe((int) $sinCiudad)) {
        test()->markTestSkipped('No hay una ciudad sin distribuidor para probar.');
    }

    // Dejarlo sin dueño es mejor que inventar uno: inventarlo esconde el problema.
    expect(app(AsignacionDeZona::class)->distribuidorDe((int) $sinCiudad))->toBeNull();
});

// ───────────────── "Asesor Asignado" sólo distribuidores ─────────────────

it('el desplegable de asesor no ofrece administradores ni encargados', function () {
    auth()->guard('user')->loginUsingId(1);

    $nombres = collect(app(AttributeRepository::class)->getLookUpOptions('distribuidores'))
        ->pluck('name');

    $rolDistribuidor = DB::table('roles')->where('name', 'Distribuidor')->value('id');
    $otros = DB::table('users')->where('status', 1)
        ->where('role_id', '!=', $rolDistribuidor)->pluck('name');

    expect($nombres)->not->toBeEmpty();

    foreach ($otros as $ajeno) {
        expect($nombres)->not->toContain($ajeno);
    }
});

it('los campos de asesor apuntan al lookup filtrado', function () {
    $tipos = DB::table('attributes')
        ->where('code', 'user_id')
        ->whereIn('entity_type', ['leads', 'persons'])
        ->pluck('lookup_type', 'entity_type');

    expect($tipos['leads'])->toBe('distribuidores')
        ->and($tipos['persons'])->toBe('distribuidores');
});

it('Propietario de Ventas sigue ofreciendo a todos', function () {
    auth()->guard('user')->loginUsingId(1);

    /*
     * Cotizaciones y organizaciones usan el MISMO método para su desplegable. Filtrar
     * por rol ahí sin querer dejaría sin opciones a dos pantallas que no tienen nada
     * que ver con el reparto.
     */
    $todos = collect(app(AttributeRepository::class)->getLookUpOptions('users'))->pluck('name');

    expect($todos)->toContain('Sofopolis');

    $tipos = DB::table('attributes')->where('code', 'user_id')
        ->whereIn('entity_type', ['quotes', 'organizations'])->pluck('lookup_type');

    foreach ($tipos as $t) {
        expect($t)->toBe('users');
    }
});

it('un pedido viejo asignado a un admin se sigue viendo', function () {
    // El valor actual se resuelve sin filtrar por rol: si no, el campo aparecería
    // vacío y guardar el pedido borraría la asignación que tenía.
    $entidad = app(AttributeRepository::class)->getLookUpEntity('distribuidores', 1);

    expect($entidad)->not->toBeNull()
        ->and($entidad->name)->toBe('Sofopolis');
});

// ───────────────── las etapas cerradas ─────────────────

it('las etapas cerradas existen de verdad en la base', function () {
    $reales = DB::table('lead_pipeline_stages')->distinct()->pluck('code')->all();

    /*
     * 🔴 Este test existe porque la lista decía `pedidos-entregados` y `pedidos-cancelados`
     * —en plural— cuando las etapas reales son `pedido-entregado` y `pedidos-cancelado`.
     * NINGUNO de los cuatro códigos coincidía con nada, así que la lista de cerradas
     * quedaba vacía y TODO pedido contaba como abierto, en silencio.
     */
    $inexistentes = array_diff(CitySyncService::CLOSED_STAGE_CODES, $reales);

    expect($inexistentes)->toBe(
        [],
        'Estas etapas cerradas no existen en lead_pipeline_stages: '.implode(', ', $inexistentes)
    );
});

// ───────────────── lo que el agente sabe del cliente ─────────────────

it('el payload dice que el cliente es conocido y no hay que pedirle el domicilio', function () {
    $palpala = ciudadDeZona('Palpalá');
    $personId = personaDePrueba('+5493884'.random_int(100000, 999999));

    app(\Webkul\Distribuidor\Services\Ubicacion::class)->guardarDelMapa(
        $personId, -24.25, -65.20, null, ['direccion' => 'Belgrano 100', 'referencia' => 'Portón verde'],
    );
    app(\Webkul\Distribuidor\Services\Bidones::class)->registrar($personId, null, 3, 0);
    pedidoComoElAgente($palpala, $personId);

    $cliente = app(AgentPayload::class)->cliente(conversacionDeZona($personId));

    expect($cliente['es_conocido'])->toBeTrue()
        ->and($cliente['datos_completos'])->toBeTrue()
        ->and($cliente['domicilio']['direccion'])->toBe('Belgrano 100')
        ->and($cliente['ciudad'])->toBe('Palpalá')
        ->and($cliente['distribuidor'])->toBe(
            DB::table('users')->where('id', distribuidorDe('Palpalá'))->value('name')
        )
        ->and($cliente['bidones_en_su_casa'])->toBe(3);
});

it('un contacto que nunca compro no cuenta como conocido', function () {
    $personId = personaDePrueba();

    /*
     * "Conocido" es haber comprado, no estar en la agenda. A alguien a quien se le abrió
     * la ficha porque escribió una vez no se le puede confirmar un domicilio que nadie
     * cargó: el agente daría por bueno un dato que no existe.
     */
    $cliente = app(AgentPayload::class)->cliente(conversacionDeZona($personId));

    expect($cliente['es_conocido'])->toBeFalse()
        ->and($cliente['datos_completos'])->toBeFalse();
});

it('los pedidos pendientes no incluyen los entregados ni los cancelados', function () {
    $palpala = ciudadDeZona('Palpalá');
    $personId = personaDePrueba();

    $abierto = pedidoComoElAgente($palpala, $personId);
    $entregado = pedidoComoElAgente($palpala, $personId);
    $cancelado = pedidoComoElAgente($palpala, $personId);

    DB::table('leads')->where('id', $entregado->id)
        ->update(['lead_pipeline_stage_id' => etapaDe($palpala, 'pedido-entregado')]);
    DB::table('leads')->where('id', $cancelado->id)
        ->update(['lead_pipeline_stage_id' => etapaDe($palpala, 'pedidos-cancelado')]);

    $ids = array_column(
        app(AgentPayload::class)->pedidosPendientes(conversacionDeZona($personId)),
        'id'
    );

    // Con la lista de etapas cerradas rota, el entregado entraba acá y el agente
    // ofrecía sumarle productos a un pedido que el cliente ya recibió.
    expect($ids)->toContain($abierto->id)
        ->and($ids)->not->toContain($entregado->id)
        ->and($ids)->not->toContain($cancelado->id);
});

it('el payload le da al agente el mapa de zonas, para no hardcodear ids', function () {
    $zonas = app(AgentPayload::class)->asignacion()['zonas'];

    $porCiudad = collect($zonas)->pluck('user_id', 'ciudad');

    /*
     * Va en el payload y no en el prompt: si los ids viven en el prompt, el día que
     * entra un distribuidor nuevo hay que editarlo, y hasta que alguien lo haga el
     * agente sigue mandando el id del anterior.
     */
    expect($porCiudad['Palpalá'])->toBe(distribuidorDe('Palpalá'))
        ->and($porCiudad['San Salvador de Jujuy'])->toBe(distribuidorDe('San Salvador de Jujuy'));
});

/** Una conversación de WhatsApp ligada a una persona. */
function conversacionDeZona(int $personId)
{
    return \Webkul\Whatsapp\Models\ConversationProxy::modelClass()::create([
        'gateway'   => 'cloud_api',
        'wa_phone'  => '+54938840'.random_int(10000, 99999),
        'wa_name'   => 'Cliente',
        'person_id' => $personId,
        'status'    => 'open',
    ]);
}
