<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Webkul\Admin\Services\InsuranceService;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->user = User::find(1);

    RateLimiter::clear('insurance-agent|unauthenticated');
    Cache::flush();
});

// Helper: mockea InsuranceService::verifyWithParams para tests positivos.
// Los tests negativos usan Http::fake porque el fallo de login de cualquier
// driver devuelve INDETERMINADO → has_insurance:false, sin necesidad de mock.
function mockVerifyWithParams(array $returnValue): void
{
    $mock = Mockery::mock(InsuranceService::class);
    $mock->shouldReceive('verifyWithParams')->once()->andReturn($returnValue);
    app()->instance(InsuranceService::class, $mock);
}

// ─── Autenticación ─────────────────────────────────────────────────────────

it('devuelve 401 sin token', function () {
    $this->withHeaders(['Accept' => 'application/json'])
        ->get('/api/v1/insurance/verify?ci_paciente=12345678&seguro_paciente=Alianza')
        ->assertStatus(401);
});

it('devuelve 422 si falta ci_paciente', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['Accept' => 'application/json'])
        ->get('/api/v1/insurance/verify?seguro_paciente=Alianza')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ci_paciente']);
});

it('devuelve 422 si falta seguro_paciente', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['Accept' => 'application/json'])
        ->get('/api/v1/insurance/verify?ci_paciente=12345678')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['seguro_paciente']);
});

it('devuelve 422 si ci_paciente tiene menos de 3 caracteres', function () {
    $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['Accept' => 'application/json'])
        ->get('/api/v1/insurance/verify?ci_paciente=12&seguro_paciente=Alianza')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ci_paciente']);
});

// ─── Respuesta negativa ─────────────────────────────────────────────────────

it('devuelve has_insurance:false cuando el webhook retorna success:false', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-INEXISTENTE-999&seguro_paciente=Alianza')
        ->assertStatus(200)
        ->assertExactJson(['has_insurance' => false, 'patient_found' => false]);
});

it('devuelve has_insurance:false cuando el webhook retorna data vacío', function () {
    Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-TEST&seguro_paciente=Alianza')
        ->assertStatus(200)
        ->assertExactJson(['has_insurance' => false, 'patient_found' => false]);
});

it('devuelve has_insurance:false cuando el webhook retorna "no registrado"', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => 'no registrado'], 200)]);

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-TEST&seguro_paciente=Alianza')
        ->assertStatus(200)
        ->assertExactJson(['has_insurance' => false, 'patient_found' => false]);
});

it('devuelve has_insurance:false cuando el webhook responde con error HTTP', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-TEST&seguro_paciente=Alianza')
        ->assertStatus(200)
        ->assertExactJson(['has_insurance' => false, 'patient_found' => false]);
});

it('la respuesta negativa no incluye patient_id ni patient_name (anti-enumeración)', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-TEST&seguro_paciente=Alianza')
        ->assertStatus(200);

    expect($response->json())->not->toHaveKey('patient_id');
    expect($response->json())->not->toHaveKey('patient_name');
});

// ─── Respuesta positiva ─────────────────────────────────────────────────────

it('devuelve has_insurance:true cuando el webhook retorna datos del paciente', function () {
    Http::fake(['*' => Http::response([
        'success' => true,
        'data'    => [[
            'CI'           => 'E-10131585',
            'Nombre'       => 'Paciente Ejemplo',
            'Observaciones' => 'MF HASTA EL 31/12/2026',
        ]],
    ], 200)]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=E-10131585&seguro_paciente=Membres%C3%ADa%20Odontoking')
        ->assertStatus(200);

    expect($response->json('has_insurance'))->toBeTrue();
    expect($response->json('insurance_name'))->toBe('Membresía Odontoking');
});

it('la respuesta positiva tiene la estructura correcta', function () {
    mockVerifyWithParams([
        'status'      => 'VIGENTE',
        'seguro_name' => 'Alianza',
        'success'     => true,
        'data'        => ['NOMBRE COMPLETO' => 'Test Paciente', 'VIGENCIA HASTA' => '31/12/2026'],
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-TEST&seguro_paciente=Alianza')
        ->assertStatus(200)
        ->assertJsonStructure([
            'has_insurance',
            'status',
            'insurance_name',
            'policy_number',
            'coverage_type',
            'valid_until',
            'covered_services',
            'patient_name',
            'raw',
        ]);
});

it('la respuesta positiva incluye patient_name desde los datos del driver', function () {
    mockVerifyWithParams([
        'status'      => 'VIGENTE',
        'seguro_name' => 'Alianza',
        'success'     => true,
        'data'        => ['NOMBRE COMPLETO' => 'María García'],
    ]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-TEST&seguro_paciente=Alianza')
        ->assertStatus(200);

    expect($response->json('patient_name'))->toBe('María García');
});

it('la respuesta positiva incluye el campo raw con datos crudos del driver', function () {
    $row = ['NOMBRE COMPLETO' => 'Test', 'ESTADO' => 'VIGENTE', 'CI' => 'CI-TEST'];

    mockVerifyWithParams([
        'status'      => 'VIGENTE',
        'seguro_name' => 'Alianza',
        'success'     => true,
        'data'        => $row,
    ]);

    $response = $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-TEST&seguro_paciente=Alianza')
        ->assertStatus(200);

    expect($response->json('raw'))->toMatchArray($row);
});

// ─── Rate Limiting ─────────────────────────────────────────────────────────

it('devuelve 429 después de 10 requests por minuto', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    for ($i = 1; $i <= 10; $i++) {
        $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/insurance/verify?ci_paciente=CI-RATE-{$i}-" . uniqid() . '&seguro_paciente=Alianza')
            ->assertStatus(200);
    }

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-RATE-11&seguro_paciente=Alianza')
        ->assertStatus(429)
        ->assertJson(['message' => 'Too many requests. Maximum 10 verifications per minute per token.']);
});

// ─── Audit Log ─────────────────────────────────────────────────────────────

it('escribe una fila en insurance_audit_logs por cada request', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    $countBefore = DB::table('insurance_audit_logs')->count();

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-AUDIT-TEST&seguro_paciente=Alianza');

    expect(DB::table('insurance_audit_logs')->count())->toBe($countBefore + 1);
});

it('el audit log guarda result:false cuando has_insurance es false', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-AUDIT-FALSE&seguro_paciente=Alianza');

    $log = DB::table('insurance_audit_logs')->latest('id')->first();
    expect((bool) $log->result)->toBeFalse();
});

it('el audit log guarda result:true cuando has_insurance es true', function () {
    mockVerifyWithParams([
        'status'      => 'VIGENTE',
        'seguro_name' => 'Alianza',
        'success'     => true,
        'data'        => ['NOMBRE COMPLETO' => 'Test'],
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-AUDIT-TRUE&seguro_paciente=Alianza');

    $log = DB::table('insurance_audit_logs')->latest('id')->first();
    expect((bool) $log->result)->toBeTrue();
});

it('el audit log NO guarda el CI en claro', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    $ci = 'CI-HASH-TEST-' . uniqid();

    $this->actingAs($this->user, 'sanctum')
        ->get("/api/v1/insurance/verify?ci_paciente={$ci}&seguro_paciente=Alianza");

    $log = DB::table('insurance_audit_logs')->latest('id')->first();

    expect(strlen($log->ci_hash))->toBe(64);
    expect($log->ci_hash)->not->toBe($ci);
    expect($log->ci_hash)->toBe(hash('sha256', $ci));
});

it('el audit log NO guarda el token en claro', function () {
    Http::fake(['*' => Http::response(['success' => false, 'data' => []], 200)]);

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-TOKEN-TEST&seguro_paciente=Alianza');

    $log = DB::table('insurance_audit_logs')->latest('id')->first();
    expect(strlen($log->token_hash))->toBe(64);
});

it('escribe audit log incluso cuando el webhook falla con 500', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $countBefore = DB::table('insurance_audit_logs')->count();

    $this->actingAs($this->user, 'sanctum')
        ->get('/api/v1/insurance/verify?ci_paciente=CI-500-TEST&seguro_paciente=Alianza');

    expect(DB::table('insurance_audit_logs')->count())->toBeGreaterThan($countBefore);
});
