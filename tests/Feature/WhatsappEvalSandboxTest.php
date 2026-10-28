<?php

/*
|--------------------------------------------------------------------------
| Entorno de evals del agente
|--------------------------------------------------------------------------
| Canal sandbox (los mensajes no salen a Meta), `whatsapp:eval-seed` (contactos
| y citas con recordatorio pendiente, re-sembrables) y CI de prueba en
| /api/v1/insurance/verify. Nada de esto funciona apuntando a producción.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\PatientContext;

beforeEach(function () {
    config([
        'whatsapp.eval.enabled'             => true,
        'whatsapp.eval.production_database' => 'base-de-produccion',
        'whatsapp.outbound_gateway'         => 'cloud_api',
    ]);

    $this->sandboxPhone = '591000'.rand(10000, 99999);
    $this->real = auditPerson(['name' => 'Real Eval '.uniqid()]);
    $this->realConv = flowConversation($this->real->id);

    config(['whatsapp.eval.contacts' => [
        ['phone' => $this->sandboxPhone, 'name' => 'Eval Sandbox', 'sandbox' => true],
        ['phone' => $this->realConv->wa_phone, 'name' => null, 'sandbox' => false],
    ]]);
});

function sandboxConversation(): Conversation
{
    $person = auditPerson(['name' => 'Sandbox '.uniqid()]);

    return Conversation::create(['gateway' => 'sandbox', 'wa_phone' => '591000'.rand(10000, 99999), 'status' => 'open', 'person_id' => $person->id]);
}

function queuedMessage(Conversation $conversation, string $type = 'text'): Message
{
    return Message::create([
        'conversation_id' => $conversation->id, 'direction' => 'outbound', 'type' => $type,
        'body'            => 'hola', 'status' => 'queued', 'sender' => 'ia',
        'payload'         => $type === 'template' ? ['template' => 'appointment_reminder', 'variables' => ['x', 'y']] : null,
    ]);
}

function evalAppointments(): \Illuminate\Support\Collection
{
    return DB::table('activities')->where('additional', 'like', '%eval_seed%')->get();
}

// ─── Canal sandbox ─────────────────────────────────────────────────────────

it('un mensaje a una conversación sandbox queda enviado sin salir a Meta, aun con el gateway forzado', function () {
    $conv = sandboxConversation();
    $text = queuedMessage($conv);
    $template = queuedMessage($conv, 'template');

    (new SendMessage($text->id))->handle(app(GatewayManager::class));
    (new SendMessage($template->id, 'cloud_api'))->handle(app(GatewayManager::class));

    expect($text->fresh()->status)->toBe('sent')
        ->and($text->fresh()->wa_message_id)->toStartWith('sandbox-')
        ->and($template->fresh()->status)->toBe('sent');
    Http::assertNothingSent();
});

it('con evals apagado una conversación sandbox no usa el sandbox', function () {
    config(['whatsapp.eval.enabled' => false]);

    expect(app(GatewayManager::class)->outbound(sandboxConversation())->key())->toBe('cloud_api');
});

it('apuntando a la base de producción no hay sandbox', function () {
    config(['whatsapp.eval.production_database' => DB::connection()->getDatabaseName()]);

    expect(app(GatewayManager::class)->outbound(sandboxConversation())->key())->toBe('cloud_api');
});

it('el agente puede mandar un formulario a una conversación sandbox sin ventana de 24h', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();
    $conv = sandboxConversation();

    $this->postJson("/api/v1/whatsapp/conversations/{$conv->id}/flows", ['flow' => 'cancelar_cita'])->assertOk();

    Queue::assertPushed(SendMessage::class);
});

// ─── whatsapp:eval-seed ─────────────────────────────────────────────────────

it('siembra un contacto sandbox y el real, cada uno con un recordatorio pendiente', function () {
    Queue::fake();

    $this->artisan('whatsapp:eval-seed')->assertSuccessful();

    $sandbox = Conversation::where('wa_phone', $this->sandboxPhone)->firstOrFail();
    expect($sandbox->gateway)->toBe('sandbox')->and($sandbox->person_id)->not->toBeNull();

    foreach ([$sandbox, $this->realConv->fresh()] as $conv) {
        $recordatorios = app(PatientContext::class)->build($conv)['recordatorios'];
        expect($recordatorios)->toHaveCount(1)->and($recordatorios[0]['respuesta'])->toBeNull();
    }

    expect((int) DB::table('leads')->where('id', $recordatorios[0]['lead_id'])->value('lead_pipeline_stage_id'))
        ->toBe((int) config('smd.stage_map.default'));
    Queue::assertPushed(SendMessage::class, 2);
});

it('re-sembrar después de que el eval contestó deja otra vez un recordatorio pendiente y borra lo anterior', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();
    $this->artisan('whatsapp:eval-seed')->assertSuccessful();

    $sandbox = Conversation::where('wa_phone', $this->sandboxPhone)->firstOrFail();
    $this->postJson("/api/v1/whatsapp/conversations/{$sandbox->id}/appointments/respond", ['respuesta' => 'confirma'])->assertOk();
    $oldLead = app(PatientContext::class)->build($sandbox)['recordatorios'][0]['lead_id'];

    $this->artisan('whatsapp:eval-seed')->assertSuccessful();

    $recordatorios = app(PatientContext::class)->build($sandbox->fresh())['recordatorios'];
    expect($recordatorios)->toHaveCount(1)
        ->and($recordatorios[0]['respuesta'])->toBeNull()
        ->and(evalAppointments())->toHaveCount(2)
        ->and(DB::table('leads')->where('id', $oldLead)->exists())->toBeFalse()
        ->and(Conversation::where('wa_phone', $this->sandboxPhone)->count())->toBe(1);
});

it('--clean borra lo sembrado y no toca el resto del historial del número real', function () {
    Queue::fake();
    $propio = queuedMessage($this->realConv);
    $this->artisan('whatsapp:eval-seed')->assertSuccessful();

    $this->artisan('whatsapp:eval-seed', ['--clean' => true])->assertSuccessful();

    expect(evalAppointments())->toBeEmpty()
        ->and(DB::table('whatsapp_appointment_reminders')->where('conversation_id', $this->realConv->id)->exists())->toBeFalse()
        ->and(Message::find($propio->id))->not->toBeNull();
});

it('no siembra con evals apagado ni apuntando a producción', function () {
    $antes = evalAppointments()->pluck('id')->all();
    config(['whatsapp.eval.enabled' => false]);
    $this->artisan('whatsapp:eval-seed')->assertFailed();

    config(['whatsapp.eval.enabled' => true, 'whatsapp.eval.production_database' => DB::connection()->getDatabaseName()]);
    $this->artisan('whatsapp:eval-seed')->assertFailed();

    expect(evalAppointments()->pluck('id')->all())->toBe($antes);
});

it('no convierte en sandbox una conversación real', function () {
    Queue::fake();
    config(['whatsapp.eval.contacts' => [['phone' => $this->realConv->wa_phone, 'name' => 'x', 'sandbox' => true]]]);

    expect(fn () => $this->artisan('whatsapp:eval-seed')->run())->toThrow(RuntimeException::class);
    expect($this->realConv->fresh()->gateway)->toBe('cloud_api');
});

// ─── CI de prueba en verify_insurance ───────────────────────────────────────

function verifyInsurance(string $ci, string $seguro): \Illuminate\Testing\TestResponse
{
    RateLimiter::clear('insurance-agent|unauthenticated');
    Cache::flush();

    return test()->actingAs(\Webkul\User\Models\User::find(1), 'sanctum')
        ->withHeaders(['Accept' => 'application/json'])
        ->get('/api/v1/insurance/verify?'.http_build_query(['ci_paciente' => $ci, 'seguro_paciente' => $seguro]));
}

it('los CI de prueba devuelven un resultado fijo sin consultar a la aseguradora', function () {
    verifyInsurance('0000001', 'Nacional Vida')->assertOk()->assertJson(['has_insurance' => true, 'policy_number' => 'EVAL-0000001']);
    verifyInsurance('0000002', 'ALIANZA')->assertOk()->assertJson(['has_insurance' => true]);
    verifyInsurance('0000003', 'Membresia Odontoking')->assertOk()->assertJson(['has_insurance' => true]);
    verifyInsurance('0000001', 'Alianza')->assertOk()->assertExactJson(['has_insurance' => false, 'patient_found' => false]);
    verifyInsurance('0000004', 'Nacional Vida')->assertOk()->assertExactJson(['has_insurance' => false, 'patient_found' => false]);

    Http::assertNothingSent();
});

it('con evals apagado un CI de prueba se consulta como cualquier otro', function () {
    config(['whatsapp.eval.enabled' => false]);
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    verifyInsurance('0000001', 'Nacional Vida')->assertOk()->assertJson(['has_insurance' => false]);

    Http::assertSentCount(1);
});

it('POST /api/insurance/verify (el que usa el agente) devuelve VIGENTE o NO_REGISTRADO para los CI de prueba', function () {
    $person = auditPerson(['name' => 'Seguro Eval '.uniqid()]);
    $verify = fn (string $ci, string $seguro) => test()->actingAs(\Webkul\User\Models\User::find(1), 'sanctum')
        ->postJson('/api/insurance/verify', ['person_id' => $person->id, 'ci' => $ci, 'insurance_type' => $seguro]);

    $verify('0000001', 'Nacional Seguro')->assertOk()->assertJson(['status' => 'VIGENTE', 'success' => true]);
    $verify('0000002', 'Alianza')->assertOk()->assertJson(['status' => 'VIGENTE']);
    $verify('0000003', 'Membresía Odontoking')->assertOk()->assertJson(['status' => 'VIGENTE']);
    $verify('0000002', 'Nacional Seguro')->assertOk()->assertJson(['status' => 'NO_REGISTRADO']);
    $verify('0000004', 'Alianza')->assertOk()->assertJson(['status' => 'NO_REGISTRADO']);

    Http::assertNothingSent();
});

// ─── Re-siembra por API ─────────────────────────────────────────────────────

it('POST eval/seed re-siembra y devuelve los recordatorios pendientes con sus ids nuevos', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();

    $first = $this->postJson('/api/v1/whatsapp/eval/seed')->assertOk()->json('contactos');
    $second = $this->postJson('/api/v1/whatsapp/eval/seed')->assertOk()->json('contactos');

    expect($second)->toHaveCount(2)
        ->and($second[0])->toMatchArray(['wa_id' => $this->sandboxPhone, 'canal' => 'sandbox'])
        ->and($second[0]['conversation_id'])->toBe($first[0]['conversation_id'])
        ->and($second[0]['recordatorios'])->toHaveCount(1)
        ->and($second[0]['recordatorios'][0]['respuesta'])->toBeNull()
        ->and($second[0]['recordatorios'][0]['activity_id'])->not->toBe($first[0]['recordatorios'][0]['activity_id']);
});

it('POST eval/seed responde 403 fuera del entorno de evals', function () {
    actingAsSanctumAuthenticatedAdmin();
    config(['whatsapp.eval.enabled' => false]);

    $this->postJson('/api/v1/whatsapp/eval/seed')->assertForbidden();
});
