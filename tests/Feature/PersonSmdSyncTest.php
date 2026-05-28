<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Webkul\Contact\Models\Person;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

it('sincroniza paciente existente en SMD y guarda smd_patient_id', function () {
    Http::fake([
        '*/patients*' => Http::response(['data' => [['_id' => 'smd-test-123']]], 200),
    ]);

    $person = Person::create([
        'name'            => 'Test Sync '.uniqid(),
        'contact_numbers' => [['value' => '77700001', 'label' => 'work']],
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->postJson(route('admin.contacts.persons.sync_smd', $person->id))
        ->assertStatus(200)
        ->assertJsonFragment(['smd_patient_id' => 'smd-test-123']);

    expect($person->fresh()->smd_patient_id)->toBe('smd-test-123');
});

it('retorna 422 si el paciente no tiene teléfono', function () {
    $person = Person::create(['name' => 'Sin Tel '.uniqid()]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->postJson(route('admin.contacts.persons.sync_smd', $person->id))
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'El paciente no tiene número de teléfono registrado.']);
});

it('retorna 422 si el paciente tiene teléfono 0', function () {
    $person = Person::create([
        'name'            => 'Tel Cero '.uniqid(),
        'contact_numbers' => [['value' => '0', 'label' => 'work']],
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->postJson(route('admin.contacts.persons.sync_smd', $person->id))
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'El paciente no tiene número de teléfono registrado.']);
});

it('sincroniza automáticamente al crear paciente con teléfono via evento', function () {
    Http::fake([
        '*/patients*' => Http::response(['data' => [['_id' => 'smd-auto-456']]], 200),
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $response = $this->actingAs($user, 'user')
        ->postJson(route('admin.contacts.persons.store'), [
            'name'            => 'Auto Sync '.uniqid(),
            'contact_numbers' => [['value' => '77788800', 'label' => 'work']],
            'entity_type'     => 'persons',
        ]);

    // 200 o redirect = creado correctamente
    expect($response->status())->toBeIn([200, 302]);

    // Buscar el paciente recién creado
    $person = \Webkul\Contact\Models\Person::where('name', 'like', 'Auto Sync%')
        ->orderBy('id', 'desc')
        ->first();

    expect($person)->not->toBeNull();
    expect($person->smd_patient_id)->toBe('smd-auto-456');
});

it('actualiza paciente en SMD al editar si ya tiene smd_patient_id', function () {
    Http::fake([
        '*/patients/smd-existing-id' => Http::response(['_id' => 'smd-existing-id', 'name' => 'Nuevo'], 200),
        '*/patients*'                => Http::response(['data' => []], 200),
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $person = Person::create([
        'name'            => 'Paciente Update '.uniqid(),
        'contact_numbers' => [['value' => '77755500', 'label' => 'work']],
        'smd_patient_id'  => 'smd-existing-id',
    ]);

    // PUT sin cabecera AJAX → 302 redirect, pero el listener igual se ejecuta
    $this->actingAs($user, 'user')
        ->put(route('admin.contacts.persons.update', $person->id), [
            'name'            => 'Paciente Actualizado',
            'contact_numbers' => [['value' => '77755500', 'label' => 'work']],
            'entity_type'     => 'persons',
        ]);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'patients/smd-existing-id'));
});

it('vincula paciente en SMD al editar si no tenía smd_patient_id pero ahora tiene teléfono', function () {
    Http::fake([
        '*/patients*' => Http::response(['data' => [['_id' => 'smd-linked-789']]], 200),
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $person = Person::create(['name' => 'Sin SMD '.uniqid()]);

    $this->actingAs($user, 'user')
        ->put(route('admin.contacts.persons.update', $person->id), [
            'name'            => 'Con Telefono Ahora',
            'contact_numbers' => [['value' => '77766600', 'label' => 'work']],
            'entity_type'     => 'persons',
        ]);

    expect($person->fresh()->smd_patient_id)->toBe('smd-linked-789');
});

it('retorna 422 si SMD no devuelve ID tras crear paciente', function () {
    Http::fake([
        '*/patients*' => Http::response(['error' => 'Internal error'], 500),
    ]);

    $person = Person::create([
        'name'            => 'Fallo SMD '.uniqid(),
        'contact_numbers' => [['value' => '77799999', 'label' => 'work']],
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->postJson(route('admin.contacts.persons.sync_smd', $person->id))
        ->assertStatus(422);
});

// ──────────────────────────────────────────────────────────────────────────────
// Flujo: searchSmd endpoint
// ──────────────────────────────────────────────────────────────────────────────

it('searchSmd retorna found:true cuando encuentra por teléfono', function () {
    Http::fake([
        '*/patients*' => Http::response([
            'data' => [[
                '_id'       => 'smd-found-by-phone',
                'name'      => 'Juan',
                'lastName'  => 'Perez',
                'phone'     => '77711111',
                'personID'  => '12345678',
            ]],
        ], 200),
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->getJson(route('admin.contacts.persons.search_smd', ['q' => '77711111']))
        ->assertStatus(200)
        ->assertJson([
            'found'  => true,
            'smd_id' => 'smd-found-by-phone',
            'name'   => 'Juan Perez',
        ]);
});

it('searchSmd retorna found:true cuando NO encuentra por teléfono pero sí por CI', function () {
    Http::fake(function ($request) {
        // Primera llamada (searchPatient por teléfono) → vacío
        // Segunda llamada (searchPatientByCi) → resultado
        // Tercera (searchPatientByEmail) no debería llegar
        static $count = 0;
        $count++;

        if ($count === 1) {
            return Http::response(['data' => []], 200);
        }

        return Http::response([
            'data' => [[
                '_id'      => 'smd-found-by-ci',
                'name'     => 'Maria',
                'lastName' => 'Lopez',
                'personID' => '9876543',
            ]],
        ], 200);
    });

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->getJson(route('admin.contacts.persons.search_smd', ['q' => '9876543']))
        ->assertStatus(200)
        ->assertJson(['found' => true, 'smd_id' => 'smd-found-by-ci']);
});

it('searchSmd retorna found:true cuando encuentra por email como tercer fallback', function () {
    Http::fake(function ($request) {
        static $count = 0;
        $count++;

        // Primera y segunda llamada sin resultados, tercera con resultado
        if ($count <= 2) {
            return Http::response(['data' => []], 200);
        }

        return Http::response([
            'data' => [[
                '_id'         => 'smd-found-by-email',
                'name'        => 'Carlos',
                'lastName'    => 'Gomez',
                'secondEmail' => 'carlos@test.com',
            ]],
        ], 200);
    });

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->getJson(route('admin.contacts.persons.search_smd', ['q' => 'carlos@test.com']))
        ->assertStatus(200)
        ->assertJson(['found' => true, 'smd_id' => 'smd-found-by-email']);
});

it('searchSmd retorna found:false con mensaje si el término tiene menos de 3 caracteres', function () {
    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->getJson(route('admin.contacts.persons.search_smd', ['q' => 'ab']))
        ->assertStatus(200)
        ->assertJson(['found' => false])
        ->assertJsonFragment(['message' => 'Ingresa al menos 3 caracteres.']);

    Http::assertNothingSent();
});

it('searchSmd retorna found:false cuando SMD no encuentra nada en ningún método', function () {
    Http::fake([
        '*/patients*' => Http::response(['data' => []], 200),
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->getJson(route('admin.contacts.persons.search_smd', ['q' => 'xyz-no-existe']))
        ->assertStatus(200)
        ->assertJson(['found' => false]);
});

// ──────────────────────────────────────────────────────────────────────────────
// Flujo: syncSmd — casos faltantes
// ──────────────────────────────────────────────────────────────────────────────

it('syncSmd retorna 404 si el ID de persona no existe', function () {
    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->postJson(route('admin.contacts.persons.sync_smd', 99999999))
        ->assertStatus(404)
        ->assertJsonFragment(['message' => 'Paciente no encontrado']);
});

it('syncSmd crea paciente en SMD cuando no existe y retorna smd_patient_id', function () {
    Http::fake([
        // searchPatient → vacío (no existe en SMD)
        '*/patients'      => Http::response(['data' => []], 200),
        // createPatient → creado exitosamente
        '*/patients/user' => Http::response(['_id' => 'smd-created-999'], 201),
    ]);

    $person = Person::create([
        'name'            => 'Nuevo Paciente '.uniqid(),
        'contact_numbers' => [['value' => '76543210', 'label' => 'work']],
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->postJson(route('admin.contacts.persons.sync_smd', $person->id))
        ->assertStatus(200)
        ->assertJsonFragment(['smd_patient_id' => 'smd-created-999', 'action' => 'creado']);

    expect($person->fresh()->smd_patient_id)->toBe('smd-created-999');
});

it('syncSmd recupera paciente de SMD cuando createPatient responde duplicado', function () {
    Http::fake(function ($request) {
        $url = $request->url();

        // Primera llamada: searchPatient → vacío
        if (str_contains($url, '/patients') && ! str_contains($url, '/patients/user') && $request->method() === 'GET') {
            static $searchCount = 0;
            $searchCount++;

            if ($searchCount === 1) {
                return Http::response(['data' => []], 200);
            }

            // Segunda búsqueda (retry tras duplicado) → encuentra
            return Http::response(['data' => [['_id' => 'smd-duplicate-recovered']]], 200);
        }

        // createPatient → duplicado
        if (str_contains($url, '/patients/user')) {
            return Http::response(['message' => '@error.duplicatePatient'], 422);
        }

        return Http::response([], 200);
    });

    $person = Person::create([
        'name'            => 'Dup Paciente '.uniqid(),
        'contact_numbers' => [['value' => '71234567', 'label' => 'work']],
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $this->actingAs($user, 'user')
        ->postJson(route('admin.contacts.persons.sync_smd', $person->id))
        ->assertStatus(200)
        ->assertJsonFragment(['smd_patient_id' => 'smd-duplicate-recovered', 'action' => 'recuperado_duplicado']);
});

// ──────────────────────────────────────────────────────────────────────────────
// Flujo: Listener syncToSmd — idempotencia
// ──────────────────────────────────────────────────────────────────────────────

it('syncToSmd NO llama SMD si el paciente ya tiene smd_patient_id al crearse', function () {
    Http::fake([
        '*/patients*' => Http::response(['data' => []], 200),
    ]);

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    // Crear paciente con smd_patient_id ya seteado desde el inicio
    // El listener syncToSmd debe salir inmediatamente sin llamar SMD
    $person = Person::create([
        'name'            => 'Ya Sincronizado '.uniqid(),
        'contact_numbers' => [['value' => '79999888', 'label' => 'work']],
        'smd_patient_id'  => 'smd-preexistente-id',
    ]);

    Http::assertNothingSent();
    expect($person->fresh()->smd_patient_id)->toBe('smd-preexistente-id');
});

it('syncToSmd NO llama SMD si la persona creada no tiene teléfono', function () {
    Http::fake([
        '*/patients*' => Http::response(['data' => []], 200),
    ]);

    Person::create([
        'name' => 'Sin Telefono '.uniqid(),
    ]);

    Http::assertNothingSent();
});

// ──────────────────────────────────────────────────────────────────────────────
// Flujo: Listener updateInSmd — not_found → limpia id → re-crea
// ──────────────────────────────────────────────────────────────────────────────

it('updateInSmd limpia smd_patient_id y crea paciente cuando updatePatient retorna not_found', function () {
    Http::fake(function ($request) {
        $url    = $request->url();
        $method = $request->method();

        // PATCH /patients/{id} → not_found
        if ($method === 'PATCH' && str_contains($url, 'patients/smd-stale-id')) {
            return Http::response(['message' => '@error.usercantfound'], 404);
        }

        // GET /patients (búsqueda de teléfono tras limpiar id) → vacío
        if ($method === 'GET' && str_contains($url, '/patients')) {
            return Http::response(['data' => []], 200);
        }

        // POST /patients/user → creado nuevo
        if ($method === 'POST' && str_contains($url, '/patients/user')) {
            return Http::response(['_id' => 'smd-new-after-stale'], 201);
        }

        return Http::response([], 200);
    });

    $user = User::find(1);
    if (! $user) {
        $this->markTestSkipped('No hay usuario con ID 1.');
    }

    $person = Person::create([
        'name'            => 'ID Obsoleto '.uniqid(),
        'contact_numbers' => [['value' => '77300200', 'label' => 'work']],
        'smd_patient_id'  => 'smd-stale-id',
    ]);

    // El listener syncToSmd se habrá ejecutado al crear; limpiar el estado para simular un id obsoleto
    // Re-setear el smd_patient_id a 'smd-stale-id' directamente vía Eloquent
    Person::where('id', $person->id)->update(['smd_patient_id' => 'smd-stale-id']);

    // Forzar el reset del Http fake para contar desde cero
    Http::fake(function ($request) {
        $url    = $request->url();
        $method = $request->method();

        if ($method === 'PATCH' && str_contains($url, 'patients/smd-stale-id')) {
            return Http::response(['message' => '@error.usercantfound'], 404);
        }

        if ($method === 'GET' && str_contains($url, '/patients')) {
            return Http::response(['data' => []], 200);
        }

        if ($method === 'POST' && str_contains($url, '/patients/user')) {
            return Http::response(['_id' => 'smd-new-after-stale'], 201);
        }

        return Http::response([], 200);
    });

    $person->refresh();

    $this->actingAs($user, 'user')
        ->put(route('admin.contacts.persons.update', $person->id), [
            'name'            => 'ID Obsoleto Actualizado',
            'contact_numbers' => [['value' => '77300200', 'label' => 'work']],
            'entity_type'     => 'persons',
        ]);

    Http::assertSent(fn ($req) => $req->method() === 'PATCH' && str_contains($req->url(), 'patients/smd-stale-id'));

    $fresh = $person->fresh();
    expect($fresh->smd_patient_id)->toBe('smd-new-after-stale');
});
