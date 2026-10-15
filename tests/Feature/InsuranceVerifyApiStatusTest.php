<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Webkul\Admin\Services\InsuranceService;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Códigos HTTP del endpoint API POST /api/insurance/verify.
 *
 * INDETERMINADO debe responder 424 (Failed Dependency), NO 5xx: el reverse-proxy
 * (Easypanel) intercepta los 502/503 del upstream y devuelve su HTML, ocultando
 * el JSON al cliente (agente IA).
 */
beforeEach(function () {
    $this->user = User::find(1);
    $this->person = Person::create(['name' => 'Paciente API '.uniqid(), 'entity_type' => 'persons']);
});

function mockVerifyDirect(array $return): void
{
    $mock = Mockery::mock(InsuranceService::class);
    $mock->shouldReceive('verifyDirect')->once()->andReturn($return);
    app()->instance(InsuranceService::class, $mock);
}

it('responde 424 (no 5xx) cuando la aseguradora da INDETERMINADO', function () {
    mockVerifyDirect([
        'status'      => 'INDETERMINADO',
        'message'     => 'No se pudo autenticar con el sistema de Alianza.',
        'badge'       => null,
        'success'     => false,
        'data'        => null,
        'seguro_name' => 'Alianza',
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/insurance/verify', [
            'person_id'      => $this->person->id,
            'ci'             => '12345678',
            'insurance_type' => 'Alianza',
        ])
        ->assertStatus(424)
        ->assertJson(['status' => 'INDETERMINADO', 'success' => false]);
});

it('responde 200 cuando la verificación es VIGENTE', function () {
    mockVerifyDirect([
        'status'      => 'VIGENTE',
        'message'     => 'Cobertura activa.',
        'badge'       => 'success',
        'success'     => true,
        'data'        => ['CI' => '12345678'],
        'seguro_name' => 'Alianza',
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/insurance/verify', [
            'person_id'      => $this->person->id,
            'ci'             => '12345678',
            'insurance_type' => 'Alianza',
        ])
        ->assertStatus(200)
        ->assertJson(['status' => 'VIGENTE', 'success' => true]);
});

it('exige autenticación', function () {
    $this->withHeaders(['Accept' => 'application/json'])
        ->postJson('/api/insurance/verify', [
            'person_id'      => $this->person->id,
            'ci'             => '12345678',
            'insurance_type' => 'Alianza',
        ])
        ->assertStatus(401);
});
