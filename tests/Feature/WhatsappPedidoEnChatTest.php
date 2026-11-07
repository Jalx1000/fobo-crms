<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Core\Services\AsignacionDeAsesor;
use Webkul\Lead\Services\CitySyncService;
use Webkul\User\Models\User;

/**
 * Gestión del pedido desde el chat: etapa, entrega, cancelación, asesor y ciudad.
 *
 * La suite corre contra la base real: todo va en una transacción y los usuarios, los
 * clientes y los pedidos son de la prueba.
 */
uses(DatabaseTransactions::class);

function chatUsuario(array $permisos, bool $puedeAsignar = false, ?string $rol = null): User
{
    $roleId = $rol
        ? (int) DB::table('roles')->where('name', $rol)->value('id')
        : (int) DB::table('roles')->insertGetId([
            'name'            => 'Rol chat '.uniqid(),
            'description'     => 'Temporal, sólo para tests',
            'permission_type' => 'custom',
            'permissions'     => json_encode($permisos),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

    return User::find((int) DB::table('users')->insertGetId([
        'name'          => 'Usuario chat '.uniqid(),
        'email'         => 'chat-'.uniqid().'@test.local',
        'password'      => bcrypt('password'),
        'status'        => 1,
        'role_id'       => $roleId,
        'puede_asignar' => $puedeAsignar,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]));
}

function chatEtapa(string $codigo, ?int $pipelineId = null): object
{
    return DB::table('lead_pipeline_stages')
        ->where('code', $codigo)
        ->when($pipelineId, fn ($q) => $q->where('lead_pipeline_id', $pipelineId))
        ->first() ?? test()->markTestSkipped("No existe la etapa {$codigo}.");
}

function chatCliente(?int $duenoId = null): int
{
    return (int) DB::table('persons')->insertGetId([
        'name'       => 'Cliente chat '.uniqid(),
        'user_id'    => $duenoId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function chatPedido(int $personId, object $etapa, ?int $duenoId = null): int
{
    return (int) DB::table('leads')->insertGetId([
        'title'                  => 'Pedido chat '.uniqid(),
        // A propósito siempre 1: nada lo baja a 0 al entregar o cancelar.
        'status'                 => 1,
        'user_id'                => $duenoId,
        'person_id'              => $personId,
        'lead_pipeline_id'       => $etapa->lead_pipeline_id,
        'lead_pipeline_stage_id' => $etapa->id,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
}

beforeEach(function () {
    if (! DB::table('roles')->where('name', AsignacionDeAsesor::ROL_ENCARGADO)->exists()) {
        $this->markTestSkipped('No existe el rol Encargado.');
    }

    $this->inicial = DB::table('lead_pipeline_stages')
        ->whereNotIn('code', CitySyncService::CLOSED_STAGE_CODES)
        ->orderBy('sort_order')
        ->first();

    $this->permisosChat = ['whatsapp', 'leads', 'leads.view', 'leads.stage_update'];
});

it('elige el pedido abierto aunque el entregado sea más nuevo y siga con status 1', function () {
    $usuario = chatUsuario($this->permisosChat);
    $personId = chatCliente();

    $abierto = chatPedido($personId, $this->inicial);
    chatPedido($personId, chatEtapa('pedido-entregado', $this->inicial->lead_pipeline_id));

    test()->actingAs($usuario, 'user')
        ->getJson(route('admin.whatsapp.pedido.index', $personId))
        ->assertOk()
        ->assertJsonPath('seleccionado', $abierto)
        ->assertJsonPath('puede.asignar', false);
});

it('un rol sin WhatsApp no ve los pedidos del chat', function () {
    $usuario = chatUsuario(['leads', 'leads.view']);

    test()->actingAs($usuario, 'user')
        ->getJson(route('admin.whatsapp.pedido.index', chatCliente()))
        ->assertForbidden();
});

it('no se puede mover la etapa de un pedido fuera del alcance', function () {
    $distribuidor = chatUsuario($this->permisosChat);

    DB::table('distribuidor_zonas')->insert([
        'user_id'          => $distribuidor->id,
        'lead_pipeline_id' => $this->inicial->lead_pipeline_id,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    $leadId = chatPedido(chatCliente(), $this->inicial, chatUsuario($this->permisosChat)->id);

    test()->actingAs($distribuidor, 'user')
        ->putJson(route('admin.leads.stage.update', $leadId), [
            'lead_pipeline_stage_id' => $this->inicial->id,
        ])
        ->assertForbidden();
});

it('entrega desde el chat con el mismo registro del Punto y sin acceso al Punto', function () {
    Storage::fake('public');

    $usuario = chatUsuario($this->permisosChat);
    $personId = chatCliente();
    $leadId = chatPedido($personId, $this->inicial);

    test()->actingAs($usuario, 'user')
        ->post(route('admin.whatsapp.pedido.entrega', $leadId), [
            'foto'               => UploadedFile::fake()->image('entrega.jpg'),
            'bidones_entregados' => 2,
            'bidones_retirados'  => 1,
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $entrega = DB::table('distribuidor_entregas')->where('lead_id', $leadId)->first();

    expect($entrega)->not->toBeNull()
        ->and((int) $entrega->user_id)->toBe((int) $usuario->id)
        ->and($entrega->foto_path)->not->toBeNull();

    expect(DB::table('lead_pipeline_stages')
        ->where('id', DB::table('leads')->where('id', $leadId)->value('lead_pipeline_stage_id'))
        ->value('code'))->toBe('pedido-entregado');
});

it('no entrega un pedido sin permiso de cambiar etapa ni uno ya cerrado', function () {
    $sinPermiso = chatUsuario(['whatsapp', 'leads', 'leads.view']);
    $conPermiso = chatUsuario($this->permisosChat);
    $personId = chatCliente();

    test()->actingAs($sinPermiso, 'user')
        ->postJson(route('admin.whatsapp.pedido.entrega', chatPedido($personId, $this->inicial)))
        ->assertForbidden();

    $cerrado = chatPedido($personId, chatEtapa('pedidos-cancelado', $this->inicial->lead_pipeline_id));

    test()->actingAs($conPermiso, 'user')
        ->postJson(route('admin.whatsapp.pedido.entrega', $cerrado))
        ->assertStatus(422);
});

it('cancelar guarda el motivo y cierra el pedido', function () {
    $usuario = chatUsuario($this->permisosChat);
    $leadId = chatPedido(chatCliente(), $this->inicial);
    $cancelado = chatEtapa('pedidos-cancelado', $this->inicial->lead_pipeline_id);

    test()->actingAs($usuario, 'user')
        ->putJson(route('admin.leads.stage.update', $leadId), [
            'lead_pipeline_stage_id' => $cancelado->id,
            'lost_reason'            => 'El cliente desistió',
        ])
        ->assertOk();

    $lead = DB::table('leads')->where('id', $leadId)->first();

    expect($lead->lost_reason)->toBe('El cliente desistió')
        ->and($lead->closed_at)->not->toBeNull();
});

it('un encargado sin el switch no puede cambiar asesor ni ciudad', function () {
    $encargado = chatUsuario([], false, AsignacionDeAsesor::ROL_ENCARGADO);
    $leadId = chatPedido(chatCliente(), $this->inicial);

    test()->actingAs($encargado, 'user')
        ->patchJson(route('admin.whatsapp.pedido.asesor', $leadId), ['user_id' => $encargado->id])
        ->assertForbidden();

    test()->actingAs($encargado, 'user')
        ->patchJson(route('admin.whatsapp.pedido.ciudad', $leadId), ['lead_pipeline_id' => $this->inicial->lead_pipeline_id])
        ->assertForbidden();
});

it('asignar a un encargado se lleva al cliente y resiste el siguiente cambio de etapa', function () {
    $habilitado = chatUsuario([], true, AsignacionDeAsesor::ROL_ENCARGADO);
    $destino = chatUsuario([], false, AsignacionDeAsesor::ROL_ENCARGADO);
    $personId = chatCliente();
    $leadId = chatPedido($personId, $this->inicial);

    test()->actingAs($habilitado, 'user')
        ->patchJson(route('admin.whatsapp.pedido.asesor', $leadId), ['user_id' => $destino->id])
        ->assertOk();

    expect((int) DB::table('persons')->where('id', $personId)->value('user_id'))->toBe((int) $destino->id)
        ->and((int) DB::table('leads')->where('id', $leadId)->value('user_id'))->toBe((int) $destino->id);

    test()->actingAs($habilitado, 'user')
        ->putJson(route('admin.leads.stage.update', $leadId), [
            'lead_pipeline_stage_id' => $this->inicial->id,
        ])
        ->assertOk();

    expect((int) DB::table('leads')->where('id', $leadId)->value('user_id'))->toBe((int) $destino->id);
});

it('no asigna a quien no tiene un rol que atienda pedidos', function () {
    $habilitado = chatUsuario([], true, AsignacionDeAsesor::ROL_ENCARGADO);
    $ajeno = chatUsuario($this->permisosChat);
    $leadId = chatPedido(chatCliente(), $this->inicial);

    test()->actingAs($habilitado, 'user')
        ->patchJson(route('admin.whatsapp.pedido.asesor', $leadId), ['user_id' => $ajeno->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');
});

it('ofrece y conserva a un distribuidor de otra ciudad al cambiar la etapa', function () {
    $otraCiudad = DB::table('lead_pipelines')->where('id', '!=', $this->inicial->lead_pipeline_id)->value('id');

    if (! $otraCiudad || ! DB::table('roles')->where('name', 'Distribuidor')->exists()) {
        $this->markTestSkipped('Hace falta otra ciudad y el rol Distribuidor.');
    }

    $habilitado = chatUsuario([], true, AsignacionDeAsesor::ROL_ENCARGADO);
    $distribuidor = chatUsuario([], false, 'Distribuidor');

    DB::table('distribuidor_zonas')->insert([
        'user_id'          => $distribuidor->id,
        'lead_pipeline_id' => $otraCiudad,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    $personId = chatCliente();
    $leadId = chatPedido($personId, $this->inicial);

    test()->actingAs($habilitado, 'user')
        ->getJson(route('admin.whatsapp.pedido.index', $personId))
        ->assertOk()
        ->assertJsonFragment([
            'id'       => (int) $distribuidor->id,
            'name'     => $distribuidor->name,
            'ciudades' => [(int) $otraCiudad],
        ]);

    test()->actingAs($habilitado, 'user')
        ->patchJson(route('admin.whatsapp.pedido.asesor', $leadId), ['user_id' => $distribuidor->id])
        ->assertOk();

    test()->actingAs($habilitado, 'user')
        ->putJson(route('admin.leads.stage.update', $leadId), [
            'lead_pipeline_stage_id' => $this->inicial->id,
        ])
        ->assertOk();

    expect((int) DB::table('leads')->where('id', $leadId)->value('user_id'))->toBe((int) $distribuidor->id);
});

it('cambiar la ciudad mueve el pedido y lleva la etapa a su equivalente', function () {
    $habilitado = chatUsuario([], true, AsignacionDeAsesor::ROL_ENCARGADO);
    $ciudades = app(\Webkul\Core\Services\AlcanceCiudades::class);

    test()->actingAs($habilitado, 'user');

    $otra = collect($ciudades->ciudades())
        ->first(fn ($ciudad) => $ciudad['id'] !== (int) $this->inicial->lead_pipeline_id
            && DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $ciudad['id'])
                ->where('code', $this->inicial->code)
                ->exists());

    if (! $otra) {
        $this->markTestSkipped('No hay otra ciudad con la misma etapa.');
    }

    $leadId = chatPedido(chatCliente(), $this->inicial);

    test()->patchJson(route('admin.whatsapp.pedido.ciudad', $leadId), ['lead_pipeline_id' => $otra['id']])
        ->assertOk();

    $lead = DB::table('leads')->where('id', $leadId)->first();

    expect((int) $lead->lead_pipeline_id)->toBe($otra['id'])
        ->and(DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code'))
        ->toBe($this->inicial->code)
        ->and((int) DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('lead_pipeline_id'))
        ->toBe($otra['id']);
});
