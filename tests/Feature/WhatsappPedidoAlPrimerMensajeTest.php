<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\LeadOpener;

/**
 * El primer mensaje de alguien abre su pedido (LeadOpener, traído de Kohlberg), a
 * nombre de Huascar si es un contacto nuevo; el pedido que después crea el agente
 * reemplaza al vacío y queda vinculado a la conversación.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();

    $this->huascar = User::create([
        'name' => 'Responsable '.uniqid(), 'email' => 'resp-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        'role_id' => Role::where('name', 'Encargado')->value('id') ?? 1, 'status' => 1,
    ]);

    config(['distribuidor.responsable_email' => $this->huascar->email, 'whatsapp.auto_create_lead' => true]);

    // Las etapas de «Sin ciudad» (migración 000014), dentro de la transacción del test.
    (require base_path('packages/Webkul/Whatsapp/src/Database/Migrations/2026_10_22_000014_etapas_para_sin_ciudad.php'))->up();
});

function primerMensaje(string $telefono, string $texto = 'Hola, quiero agua'): object
{
    (new IngestInboundPayload('cloud_api', ['entry' => [['changes' => [['value' => [
        'contacts' => [['profile' => ['name' => 'Cliente Nuevo']]],
        'messages' => [['id' => 'wamid.'.uniqid(), 'from' => $telefono, 'type' => 'text', 'text' => ['body' => $texto]]],
    ]]]]]]))->handle(app(GatewayManager::class), app(ConversationResolver::class));

    return ConversationProxy::modelClass()::where('wa_phone', '+'.$telefono)->firstOrFail();
}

function primerTelefono(): string
{
    return '54938'.random_int(10000000, 99999999);
}

it('el primer mensaje de un contacto nuevo abre su pedido, vacío, a nombre de Huascar', function () {
    $conv = primerMensaje(primerTelefono());
    $lead = DB::table('leads')->where('id', $conv->lead_id)->first();

    expect($lead)->not->toBeNull()
        ->and($lead->title)->toStartWith(LeadOpener::TITLE_PREFIX)
        ->and((int) $lead->person_id)->toBe((int) $conv->person_id)
        ->and((int) $lead->user_id)->toBe($this->huascar->id)
        ->and(DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code'))->toBe('prospectos');
});

it('la conversación nueva no prende el agente por su cuenta: hereda lo general', function () {
    expect(primerMensaje(primerTelefono())->ai_enabled)->toBeNull();
});

it('el agente recibe ese pedido como vacío, para completarlo con PUT', function () {
    $conv = primerMensaje(primerTelefono());
    $mensaje = $conv->messages()->latest('id')->first();

    $lead = app(AgentPayload::class)->event($mensaje->fresh('conversation'))['lead'];

    expect($lead['id'])->toBe((int) $conv->lead_id)
        ->and($lead['is_empty'])->toBeTrue();
});

it('un segundo mensaje no abre otro pedido', function () {
    $telefono = primerTelefono();
    $conv = primerMensaje($telefono);
    primerMensaje($telefono, 'Soy de Palpalá');

    expect(DB::table('leads')->where('person_id', $conv->person_id)->count())->toBe(1);
});

it('si el agente crea el pedido con POST, reemplaza al vacío y queda vinculado', function () {
    $conv = primerMensaje(primerTelefono());
    $vacio = (int) $conv->lead_id;

    $ciudad = (int) DB::table('lead_pipelines')->where('name', 'like', 'Palpal%')->value('id');
    $etapa = (int) DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $ciudad)->where('code', 'prospectos')->value('id');

    $real = app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => 'Pedido botellones – Cliente', 'lead_value' => 7500,
        'person' => ['id' => $conv->person_id], 'lead_pipeline_id' => $ciudad, 'lead_pipeline_stage_id' => $etapa,
        'lead_source_id' => 6, 'lead_type_id' => 1, 'status' => 1,
    ]);
    event('lead.create.after', $real);

    expect(DB::table('leads')->where('id', $vacio)->exists())->toBeFalse()
        ->and((int) $conv->fresh()->lead_id)->toBe((int) $real->id);
});

it('un pedido con contenido no se borra cuando llega otro', function () {
    $conv = primerMensaje(primerTelefono());
    $primero = (int) $conv->lead_id;
    DB::table('leads')->where('id', $primero)->update(['lead_value' => 8000]);

    $segundo = app(LeadRepository::class)->create([
        'entity_type' => 'leads', 'title' => 'Otro pedido', 'lead_value' => 7500, 'person' => ['id' => $conv->person_id],
        'lead_pipeline_stage_id' => DB::table('leads')->where('id', $primero)->value('lead_pipeline_stage_id'),
        'lead_pipeline_id' => DB::table('leads')->where('id', $primero)->value('lead_pipeline_id'),
        'lead_source_id' => 6, 'lead_type_id' => 1, 'status' => 1,
    ]);
    event('lead.create.after', $segundo);

    expect(DB::table('leads')->where('id', $primero)->exists())->toBeTrue();
});

it('con WHATSAPP_AUTO_CREATE_LEAD=false no abre pedidos', function () {
    config(['whatsapp.auto_create_lead' => false]);

    expect(primerMensaje(primerTelefono())->lead_id)->toBeNull();
});
