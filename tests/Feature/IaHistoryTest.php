<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;

use function Pest\Laravel\get;

uses(DatabaseTransactions::class);

/**
 * Este test valida el endpoint público de historial de chat con la IA.
 * Se utiliza para alimentar la pestaña "Historial IA" en Leads y Personas.
 */
it('retorna error 400 si no se proporciona el email', function () {
    $response = get('/api/public/chat-history');

    $response->assertStatus(400)
        ->assertJson(['error' => 'email es requerido']);
});

it('puede consultar el historial de un email (aunque esté vacío)', function () {
    // Verificar que la conexión externa a PostgreSQL está disponible en este entorno
    try {
        \Illuminate\Support\Facades\DB::connection('external_pgsql')->getPdo();
    } catch (\Exception $e) {
        test()->markTestSkipped('Conexión external_pgsql no disponible en este entorno: '.$e->getMessage());
    }

    $email = 'test.'.uniqid().'@example.com';

    $response = get('/api/public/chat-history?email='.$email);

    $response->assertStatus(200)
        ->assertJsonStructure(['messages']);
});

it('valida que el componente de historial IA sea accesible en la vista de Leads', function () {
    $adminUser = \Webkul\User\Models\User::find(1);
    $lead = \Webkul\Lead\Models\Lead::first();

    if ($lead) {
        $this->actingAs($adminUser, 'user')
            ->get(route('admin.leads.view', $lead->id))
            ->assertStatus(200)
            ->assertSee('v-historial-ia')
            ->assertSee('Historial IA');
    } else {
        $this->markTestSkipped('No hay leads para probar la vista.');
    }
});

it('valida que el componente de historial IA sea accesible en la vista de Personas', function () {
    $adminUser = \Webkul\User\Models\User::find(1);
    $person = \Webkul\Contact\Models\Person::first();

    if ($person) {
        $this->actingAs($adminUser, 'user')
            ->get(route('admin.contacts.persons.view', $person->id))
            ->assertStatus(200)
            ->assertSee('v-historial-ia')
            ->assertSee('Historial IA');
    } else {
        $this->markTestSkipped('No hay personas para probar la vista.');
    }
});
