<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Flows: envío del formulario y recepción de la respuesta
|--------------------------------------------------------------------------
| - Herramienta del agente POST /api/v1/whatsapp/conversations/{id}/flows
| - SendMessage arma el mensaje interactivo de tipo flow para Cloud API
| - El webhook nfm_reply se guarda como `flow_reply` y llega al agente con
|   los datos estructurados
*/

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Gateways\CloudApi\CloudApiGateway;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\AgentPayload;
use Webkul\Whatsapp\Services\Flows\FlowToken;

beforeEach(function () {
    config([
        'whatsapp.outbound_gateway'                   => 'cloud_api',
        'whatsapp.gateways.cloud_api.token'           => 'test-token',
        'whatsapp.gateways.cloud_api.phone_number_id' => '111222333',
        'whatsapp.flows.patient.name'                 => 'odontoking_paciente_tercero',
        'whatsapp.flows.patient.id'                   => null,
        'whatsapp.flows.patient.cta'                  => 'Completar datos',
    ]);

    $this->conversation = Conversation::create([
        'gateway'         => 'cloud_api',
        'wa_phone'        => '5916'.rand(1000000, 9999999),
        'status'          => 'open',
        'last_inbound_at' => now()->subMinutes(5),
    ]);
});

it('la herramienta del agente encola un mensaje de tipo flow con un token de esta conversación', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();

    $res = $this->postJson("/api/v1/whatsapp/conversations/{$this->conversation->id}/flows", ['flow' => 'paciente_tercero'])
        ->assertOk()
        ->assertJsonPath('message.type', 'flow');

    $message = Message::find($res->json('message.id'));

    expect($message->type)->toBe('flow')
        ->and($message->payload['flow']['name'])->toBe('odontoking_paciente_tercero')
        ->and(FlowToken::conversationId($res->json('flow_token')))->toBe($this->conversation->id);

    Queue::assertPushed(SendMessage::class);
});

it('rechaza flows desconocidos y conversaciones fuera de la ventana de 24 h', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();

    $this->postJson("/api/v1/whatsapp/conversations/{$this->conversation->id}/flows", ['flow' => 'otro'])->assertStatus(422);

    $this->conversation->forceFill(['last_inbound_at' => now()->subDays(2)])->save();
    $this->postJson("/api/v1/whatsapp/conversations/{$this->conversation->id}/flows", ['flow' => 'paciente_tercero'])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('exige autenticación', function () {
    $this->postJson("/api/v1/whatsapp/conversations/{$this->conversation->id}/flows", ['flow' => 'paciente_tercero'])
        ->assertStatus(401);
});

it('SendMessage manda a Meta un interactive de tipo flow con data_exchange y el token', function () {
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.FLOW1']]], 200)]);

    $token = FlowToken::issue($this->conversation->id);
    $message = Message::create([
        'conversation_id' => $this->conversation->id,
        'direction'       => 'outbound',
        'type'            => 'flow',
        'body'            => 'Completá los datos',
        'status'          => 'queued',
        'sender'          => 'ia',
        'payload'         => ['flow' => ['token' => $token, 'name' => 'odontoking_paciente_tercero', 'id' => null, 'cta' => 'Completar datos']],
    ]);

    (new SendMessage($message->id))->handle(app(\Webkul\Whatsapp\Gateways\GatewayManager::class));

    expect($message->fresh()->status)->toBe('sent')
        ->and($message->fresh()->wa_message_id)->toBe('wamid.FLOW1');

    Http::assertSent(function ($request) use ($token) {
        $i = $request->data()['interactive'] ?? [];

        return str_ends_with($request->url(), '/111222333/messages')
            && $request->data()['type'] === 'interactive'
            && $i['type'] === 'flow'
            && $i['body']['text'] === 'Completá los datos'
            && $i['action']['parameters']['flow_token'] === $token
            && $i['action']['parameters']['flow_action'] === 'data_exchange'
            && $i['action']['parameters']['flow_name'] === 'odontoking_paciente_tercero'
            && $i['action']['parameters']['flow_message_version'] === '3';
    });
});

it('el webhook nfm_reply se interpreta como flow_reply con un resumen legible', function () {
    $responseJson = json_encode([
        'flow_token' => 'x', 'flow' => 'paciente_tercero', 'status' => 'created',
        'patient_id' => 4521, 'patient_name' => 'Juan Perez', 'relation' => 'hijo',
    ]);

    $batch = app(CloudApiGateway::class, ['config' => config('whatsapp.gateways.cloud_api')])->parseWebhook([
        'entry' => [['changes' => [['value' => [
            'contacts' => [['profile' => ['name' => 'Mama']]],
            'messages' => [[
                'id'          => 'wamid.REPLY1',
                'from'        => '59160000000',
                'type'        => 'interactive',
                'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'body' => 'Sent', 'response_json' => $responseJson]],
            ]],
        ]]]]],
    ]);

    expect($batch->messages[0]->type)->toBe('flow_reply')
        ->and($batch->messages[0]->body)->toBe('📋 Formulario: cita para Juan Perez (paciente nuevo)');
});

it('una cita agendada por el formulario se muestra en el inbox con doctor y horario', function () {
    $responseJson = json_encode([
        'flow_token'   => 'x', 'flow' => 'paciente_tercero', 'status' => 'booked', 'patient_id' => 1,
        'patient_name' => 'Juan Perez', 'doctor_name' => 'CAROLINA ROJAS', 'date' => '2026-10-13', 'time' => '09:30',
    ]);

    $batch = app(CloudApiGateway::class, ['config' => config('whatsapp.gateways.cloud_api')])->parseWebhook([
        'entry' => [['changes' => [['value' => ['messages' => [[
            'id'          => 'wamid.REPLY2', 'from' => '59160000000', 'type' => 'interactive',
            'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => ['name' => 'flow', 'body' => 'Sent', 'response_json' => $responseJson]],
        ]]]]]]],
    ]);

    expect($batch->messages[0]->body)->toBe('📅 Cita agendada: Juan Perez · CAROLINA ROJAS · 2026-10-13 09:30');
});

it('al agente le llegan los datos del formulario estructurados y sin el flow_token', function () {
    $message = Message::create([
        'conversation_id' => $this->conversation->id,
        'direction'       => 'inbound',
        'type'            => 'flow_reply',
        'body'            => '📋 Formulario: cita para Juan Perez (paciente nuevo)',
        'status'          => 'delivered',
        'sender'          => 'contact',
        'payload'         => ['interactive' => ['nfm_reply' => ['response_json' => json_encode([
            'flow_token' => 'secreto', 'flow' => 'paciente_tercero', 'status' => 'created', 'patient_id' => 4521, 'patient_name' => 'Juan Perez',
        ])]]],
    ]);

    $event = app(AgentPayload::class)->event($message);

    expect($event['message']['type'])->toBe('flow_reply')
        ->and($event['message']['flow'])->toBe([
            'flow' => 'paciente_tercero', 'status' => 'created', 'patient_id' => 4521, 'patient_name' => 'Juan Perez',
        ]);
});

it('el contexto del agente incluye los pacientes a cargo y las citas del hijo sin teléfono', function () {
    $mama = \Webkul\Contact\Models\Person::create(['name' => 'Mama Contexto '.uniqid(), 'entity_type' => 'persons',
        'contact_numbers'                                 => [['value' => $this->conversation->wa_phone, 'label' => 'work']]]);
    $hijo = \Webkul\Contact\Models\Person::create(['name' => 'Hijo Contexto '.uniqid(), 'entity_type' => 'persons', 'contact_numbers' => []]);
    $this->conversation->forceFill(['person_id' => $mama->id])->save();

    \Illuminate\Support\Facades\DB::table('person_relations')->insert([
        'patient_person_id' => $hijo->id, 'responsible_person_id' => $mama->id,
        'relation'          => 'hijo', 'source' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $lead = \Webkul\Lead\Models\Lead::create(['title' => 'Cita del hijo', 'description' => 't', 'entity_type' => 'leads']);
    \Illuminate\Support\Facades\DB::table('leads')->where('id', $lead->id)->update([
        'person_id' => $hijo->id, 'lead_pipeline_stage_id' => (int) config('whatsapp.crm.stage_activa', 7),
    ]);

    $context = app(\Webkul\Whatsapp\Services\PatientContext::class)->build($this->conversation->fresh());

    expect($context['pacientes_a_cargo'])->toBe([['person_id' => $hijo->id, 'nombre' => $hijo->name, 'relacion' => 'hijo']])
        ->and(collect($context['citas'])->pluck('paciente')->all())->toContain($hijo->name);
});

it('el inbox muestra el formulario enviado con su botón y la respuesta del paciente', function () {
    $conversation = $this->conversation;

    Message::create([
        'conversation_id' => $conversation->id, 'direction' => 'outbound', 'type' => 'flow', 'sender' => 'ia',
        'body'            => 'Completá tus datos para agendar 👇', 'status' => 'sent',
        'payload'         => ['flow' => ['key' => 'paciente_tercero', 'cta' => 'Agendar cita']],
    ]);
    Message::create([
        'conversation_id' => $conversation->id, 'direction' => 'inbound', 'type' => 'flow_reply', 'sender' => 'contact',
        'body'            => '📅 Pidió cita: Juan Perez · CAROLINA ROJAS · 2026-10-15 10:00 (confirmando)', 'status' => 'delivered',
    ]);

    $messages = \Pest\Laravel\actingAs(\Webkul\User\Models\User::find(1), 'user')
        ->getJson(route('admin.whatsapp.thread', ['conversation_id' => $conversation->id]))
        ->assertOk()
        ->json('messages');

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toMatchArray(['type' => 'flow', 'text' => 'Completá tus datos para agendar 👇', 'flow' => ['cta' => 'Agendar cita']])
        ->and($messages[1])->toMatchArray(['type' => 'flow_reply', 'flow' => null])
        ->and($messages[1]['text'])->toContain('Pidió cita');

    // La vista tiene una rama para cada uno (antes caían en "Tipo no soportado").
    $view = file_get_contents(base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php'));
    expect($view)->toContain("msg.type === 'flow'")->toContain("msg.type === 'flow_reply'");
});
