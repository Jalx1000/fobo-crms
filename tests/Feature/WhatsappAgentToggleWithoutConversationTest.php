<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\Conversation;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/*
| Un paciente que nunca escribió no tiene conversación: el chat aplicaba el
| agente global (encendido) y bloqueaba la caja de escribir, pero el switch no
| tenía dónde guardar el «apagado» y no hacía nada. Apagarlo ahora crea la
| conversación del contacto, la misma que crearía el primer mensaje.
*/

beforeEach(function () {
    config(['whatsapp.ai.enabled' => true]);

    $this->phone = '7'.rand(1000000, 9999999);
    // Con smd_patient_id no se consulta SMD al vincular el número.
    $this->person = Person::create([
        'name'            => 'Paciente Sin Chat '.uniqid(),
        'contact_numbers' => [['value' => $this->phone, 'label' => 'work']],
        'entity_type'     => 'persons',
        'smd_patient_id'  => rand(900000, 999999),
    ]);
});

it('sin conversación el chat se ve con el agente activo', function () {
    actingAs(User::find(1), 'user')
        ->getJson(route('admin.whatsapp.thread', ['phone' => $this->phone, 'person_id' => $this->person->id]))
        ->assertOk()
        ->assertJsonPath('conversation', null)
        ->assertJsonPath('agent.effective', true);
});

it('apagar el agente sin conversación la crea vinculada al paciente y desbloquea el chat', function () {
    $res = actingAs(User::find(1), 'user')
        ->patchJson(route('admin.whatsapp.agent.contact'), [
            'enabled'   => false,
            'phone'     => $this->phone,
            'name'      => $this->person->name,
            'person_id' => $this->person->id,
        ])
        ->assertOk()
        ->assertJsonPath('agent.effective', false);

    $conversation = Conversation::find($res->json('conversation.id'));

    expect($conversation)->not->toBeNull()
        ->and($conversation->person_id)->toBe($this->person->id)
        ->and($conversation->ai_enabled)->toBeFalse()
        ->and($conversation->ai_disabled_at)->not->toBeNull();

    // El próximo poll ya no lo vuelve a bloquear.
    actingAs(User::find(1), 'user')
        ->getJson(route('admin.whatsapp.thread', ['phone' => $this->phone, 'person_id' => $this->person->id]))
        ->assertJsonPath('conversation.id', $conversation->id)
        ->assertJsonPath('agent.effective', false);
});

it('si ya hay conversación la reutiliza en vez de duplicarla', function () {
    $existing = Conversation::create([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '591'.$this->phone,
        'status'     => 'open',
        'person_id'  => $this->person->id,
        'ai_enabled' => true,
    ]);

    actingAs(User::find(1), 'user')
        ->patchJson(route('admin.whatsapp.agent.contact'), [
            'enabled'   => false,
            'phone'     => $this->phone,
            'person_id' => $this->person->id,
        ])
        ->assertOk()
        ->assertJsonPath('conversation.id', $existing->id);

    expect(Conversation::where('person_id', $this->person->id)->count())->toBe(1)
        ->and($existing->fresh()->ai_enabled)->toBeFalse();
});

it('el toggle por id también devuelve la conversación', function () {
    $existing = Conversation::create([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '591'.$this->phone,
        'status'     => 'open',
        'person_id'  => $this->person->id,
        'ai_enabled' => true,
    ]);

    actingAs(User::find(1), 'user')
        ->patchJson(route('admin.whatsapp.agent', $existing->id), ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('conversation.id', $existing->id)
        ->assertJsonPath('agent.effective', false);
});

it('sin teléfono no se crea nada', function () {
    actingAs(User::find(1), 'user')
        ->patchJson(route('admin.whatsapp.agent.contact'), ['enabled' => false, 'person_id' => $this->person->id])
        ->assertStatus(422);
});
