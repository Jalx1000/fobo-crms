<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Webkul\Attribute\Models\Attribute;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/**
 * Este test valida la lógica de verificación de seguros con n8n.
 * Cubre los 4 estados: Vigente, En Mora, No Registrado e Indeterminado.
 */
beforeEach(function () {
    $this->adminUser = User::find(1);

    // Crear paciente de prueba
    $this->person = Person::create([
        'name' => 'Paciente de Prueba '.uniqid(),
    ]);

    // Asegurar que existan los atributos ci_paciente y seguro_paciente
    $this->ciAttr = Attribute::where('code', 'ci_paciente')->first() ?: Attribute::create([
        'code' => 'ci_paciente', 'name' => 'CI', 'type' => 'text', 'entity_type' => 'persons', 'admin_name' => 'CI',
    ]);

    $this->seguroAttr = Attribute::where('code', 'seguro_paciente')->first() ?: Attribute::create([
        'code' => 'seguro_paciente', 'name' => 'Seguro', 'type' => 'select', 'entity_type' => 'persons', 'admin_name' => 'Seguro',
    ]);
});

it('falla con 422 si faltan CI o Seguro y devuelve las opciones', function () {
    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertStatus(422)
        ->assertJson(['status' => 'CAMPOS_VACIOS', 'message' => 'Faltan datos importantes para la validación.'])
        ->assertJsonStructure(['options']);
});

it('permite actualizar y verificar el seguro simultáneamente', function () {
    // Mock de n8n
    Http::fake([
        'n8n.sofopolis.com/*' => Http::response([
            'success' => true,
            'data'    => [['ESTADO' => 'VIGENTE', 'NOMBRE COMPLETO' => 'PACIENTE ACTUALIZADO']],
        ], 200),
    ]);

    $updateData = [
        'ci_paciente'     => '1234567',
        'seguro_paciente' => 113, // Nacional Vida
    ];

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.update_insurance', $this->person->id), $updateData)
        ->assertStatus(200)
        ->assertJson(['status' => 'VIGENTE', 'message' => '¡Todo en orden! El seguro está activo y el paciente puede ser atendido.']);

    $this->assertDatabaseHas('attribute_values', [
        'entity_id'  => $this->person->id,
        'text_value' => '1234567',
    ]);
});

it('crea una nota automática después de una verificación exitosa', function () {
    $this->person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '5856583', 'entity_type' => 'persons']);
    $this->person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, 'integer_value' => 113, 'entity_type' => 'persons']);

    Http::fake([
        'n8n.sofopolis.com/*' => Http::response([
            'success' => true,
            'data'    => [['ESTADO' => 'VIGENTE', 'CONTRATANTE' => 'TEST ASEGURADORA']],
        ], 200),
    ]);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id));

    $this->assertDatabaseHas('activities', [
        'type'  => 'note',
        'title' => 'Verificación de Seguro: VIGENTE',
    ]);
});

it('mapea correctamente un paciente VIGENTE desde n8n', function () {
    $this->person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '5856583', 'entity_type' => 'persons']);
    $this->person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, 'integer_value' => 113, 'entity_type' => 'persons']);

    Http::fake([
        'n8n.sofopolis.com/*' => Http::response([
            'success' => true,
            'data'    => [[
                'NOMBRE COMPLETO'        => 'JAVIER ALEJANDRO',
                'ESTADO'                 => 'VIGENTE',
                'COASEGURO ODONTOLOGICO' => '$US. 7',
            ]],
        ], 200),
    ]);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertStatus(200)
        ->assertJson([
            'status'  => 'VIGENTE',
            'badge'   => 'success',
            'message' => '¡Todo en orden! El seguro está activo y el paciente puede ser atendido.',
        ]);
});

it('mapea correctamente un paciente EN_MORA (Suspendido) desde n8n', function () {
    $this->person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '5856583', 'entity_type' => 'persons']);
    $this->person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, 'integer_value' => 113, 'entity_type' => 'persons']);

    Http::fake([
        'n8n.sofopolis.com/*' => Http::response([
            'success' => true,
            'data'    => [[
                'NOMBRE COMPLETO' => 'PACIENTE MOROSO',
                'ESTADO'          => 'SUSPENDIDO',
            ]],
        ], 200),
    ]);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertStatus(200)
        ->assertJson([
            'status'  => 'EN_MORA',
            'badge'   => 'danger',
            'message' => 'Atención: El seguro está suspendido. El paciente podría tener pagos pendientes.',
        ]);
});

it('mapea correctamente un paciente NO_REGISTRADO', function () {
    $this->person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '0000000', 'entity_type' => 'persons']);
    $this->person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, 'integer_value' => 113, 'entity_type' => 'persons']);

    Http::fake([
        'n8n.sofopolis.com/*' => Http::response([
            'success' => false,
            'data'    => [],
        ], 200),
    ]);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertStatus(200)
        ->assertJson([
            'status'  => 'NO_REGISTRADO',
            'badge'   => 'warning',
            'message' => 'El paciente no figura en la base de datos de esta aseguradora.',
        ]);
});

it('maneja el estado INDETERMINADO por timeout o error de red', function () {
    $this->person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '5856583', 'entity_type' => 'persons']);
    $this->person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, 'integer_value' => 113, 'entity_type' => 'persons']);

    Http::fake([
        'n8n.sofopolis.com/*' => function () {
            throw new \Exception('Timeout connecting to n8n');
        },
    ]);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertStatus(200)
        ->assertJson([
            'status'  => 'INDETERMINADO',
            'message' => 'La consulta tardó demasiado. Por favor, revisa tu conexión e inténtalo de nuevo.',
        ]);
});

it('maneja el estado INDETERMINADO cuando n8n devuelve JSON inválido o vacío', function () {
    $this->person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '5856583', 'entity_type' => 'persons']);
    $this->person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, 'integer_value' => 113, 'entity_type' => 'persons']);

    Http::fake([
        'n8n.sofopolis.com/*' => Http::response('', 200), // Respuesta vacía (no es un array JSON)
    ]);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertStatus(200)
        ->assertJson([
            'status'  => 'INDETERMINADO',
            'message' => 'La aseguradora respondió con un formato que no conocemos. Por favor, contacta a soporte.',
        ]);
});

it('mapea correctamente un paciente NO_REGISTRADO (caso string)', function () {
    $this->person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '12387735', 'entity_type' => 'persons']);
    $this->person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, 'integer_value' => 113, 'entity_type' => 'persons']);

    Http::fake([
        'n8n.sofopolis.com/*' => Http::response([
            'success' => true,
            'data'    => 'no registrado',
        ], 200),
    ]);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertStatus(200)
        ->assertJson([
            'status'  => 'NO_REGISTRADO',
            'badge'   => 'warning',
            'message' => 'El paciente no figura en la base de datos de esta aseguradora.',
        ]);
});

it('utiliza la caché para evitar llamadas redundantes a n8n', function () {
    $this->person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '5856583', 'entity_type' => 'persons']);
    $this->person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, 'integer_value' => 113, 'entity_type' => 'persons']); // 87 = Nacional Vida

    // Mock de n8n que solo responde UNA vez
    Http::fake([
        'n8n.sofopolis.com/*' => Http::sequence()
            ->push(['success' => true, 'data' => [['ESTADO' => 'VIGENTE']]], 200)
            ->pushStatus(500), // La segunda llamada fallaría si no hubiera caché
    ]);

    // Primera llamada: debe ir a n8n
    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertJson(['status' => 'VIGENTE']);

    // Segunda llamada: debe venir de caché (no fallará con 500)
    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertJson(['status' => 'VIGENTE']);
});

it('valida reglas de negocio en tiempo real ante cambios de aseguradora', function () {
    // Este test simula lo que el componente Vue haría al recibir el evento global
    // Caso 1: Cambio a "No tiene"
    $service = app(\Webkul\Admin\Services\InsuranceService::class);

    // Forzamos el atributo a "No tiene" (ID 89 según tinker)
    $this->person->attribute_values()->updateOrCreate(
        ['attribute_id' => $this->seguroAttr->id, 'entity_type' => 'persons'],
        ['integer_value' => 115]
    );

    $this->person->attribute_values()->updateOrCreate(
        ['attribute_id' => $this->ciAttr->id, 'entity_type' => 'persons'],
        ['text_value' => '5856583']
    );

    $result = $service->verify($this->person->id);
    expect($result['status'])->toBe('SIN_SEGURO');
    expect($result['message'])->toContain('no cuenta con un seguro');

    // Caso 2: Cambio a Alianza (ID 86) - usa MedinetGo directamente (no n8n).
    // En entorno de test ALIANZA_LOGIN_URL no está configurado → retorna INDETERMINADO.
    $this->person->attribute_values()->updateOrCreate(
        ['attribute_id' => $this->seguroAttr->id, 'entity_type' => 'persons'],
        ['integer_value' => 112, 'text_value' => '5856583']
    );

    Cache::flush();
    $result = $service->verify($this->person->id);
    // Alianza tiene driver propio (MedinetGo). Sin credenciales configuradas → INDETERMINADO.
    expect($result['status'])->toBeIn(['VIGENTE', 'INDETERMINADO', 'NO_REGISTRADO']);
});

it('detecta correctamente cuando un paciente tiene la opción "No tiene" seguro', function () {
    // Simulamos que el seguro seleccionado es "No tiene"
    // Primero debemos obtener el ID de la opción "No tiene" o usar uno conocido del negocio
    // Según tinker previo, ID 89 es "No tiene"

    $this->person->attribute_values()->create(['attribute_id' => $this->ciAttr->id, 'text_value' => '5856583', 'entity_type' => 'persons']);
    $this->person->attribute_values()->create(['attribute_id' => $this->seguroAttr->id, 'integer_value' => 115, 'entity_type' => 'persons']);

    // No debería llamar a n8n, así que si Http falla es porque no interceptó el caso
    Http::fake([
        'n8n.sofopolis.com/*' => Http::response([], 500),
    ]);

    actingAs($this->adminUser, 'user')
        ->post(route('admin.contacts.persons.verify_insurance', $this->person->id))
        ->assertStatus(200)
        ->assertJson([
            'status'  => 'SIN_SEGURO',
            'message' => 'El paciente no cuenta con un seguro registrado en su perfil.',
        ]);
});
