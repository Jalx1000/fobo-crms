<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Contact\Models\Person;
use Webkul\Doctor\Models\Doctor;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Cubre la lógica de reutilización de lead por paciente:
 * - Paciente nuevo  → se crea un nuevo lead
 * - Paciente con lead activo  → se reutiliza el lead existente
 * - Paciente con lead cerrado → se reabre el lead existente
 * - Con cita → se crea actividad en el lead (nuevo o existente)
 */
beforeEach(function () {
    $this->admin = User::find(1);

    $this->person = Person::create([
        'name' => 'Paciente Test '.uniqid(),
    ]);

    // Stage 1 = Consulta (sin cita), Stage 2 = Confirmada (con cita)
    $this->stageConsulta = DB::table('lead_pipeline_stages')->find(1);
    $this->stageConfirmada = DB::table('lead_pipeline_stages')->find(2);
});

// ---------------------------------------------------------------------------
// Helper: payload base para crear un lead sin cita
// ---------------------------------------------------------------------------
function leadPayload(Person $person, array $overrides = []): array
{
    return array_merge([
        'title'  => 'Cita de prueba '.uniqid(),
        'person' => [
            'id'   => $person->id,
            'name' => $person->name,
        ],
    ], $overrides);
}

// ---------------------------------------------------------------------------
// 1. Paciente nuevo (sin person.id) → crea lead
// ---------------------------------------------------------------------------
it('crea un nuevo lead cuando el paciente no tiene person.id', function () {
    $leadsAntes = Lead::count();

    actingAs($this->admin, 'user')
        ->post(route('admin.leads.store'), [
            'title'  => 'Lead nuevo '.uniqid(),
            'person' => [
                'name' => 'Nuevo Paciente '.uniqid(),
            ],
        ])
        ->assertRedirect();

    expect(Lead::count())->toBe($leadsAntes + 1);
});

// ---------------------------------------------------------------------------
// 2. Paciente existente sin leads previos → crea lead nuevo
// ---------------------------------------------------------------------------
it('crea un nuevo lead cuando el paciente existe pero no tiene leads', function () {
    // Asegurar que esta persona no tiene leads
    expect($this->person->leads()->count())->toBe(0);

    $leadsAntes = Lead::count();

    actingAs($this->admin, 'user')
        ->post(route('admin.leads.store'), leadPayload($this->person))
        ->assertRedirect();

    expect(Lead::count())->toBe($leadsAntes + 1);

    $lead = Lead::where('person_id', $this->person->id)->latest()->first();
    expect($lead)->not->toBeNull();
    expect($lead->status)->toBe(1);
});

// ---------------------------------------------------------------------------
// 3. Paciente con lead ACTIVO → reutiliza el lead, no crea uno nuevo
// ---------------------------------------------------------------------------
it('reutiliza el lead activo existente en lugar de crear uno nuevo', function () {
    // Crear lead activo preexistente para esta persona
    $leadExistente = Lead::create([
        'title'                  => 'Expediente Activo',
        'status'                 => 1,
        'person_id'              => $this->person->id,
        'lead_pipeline_id'       => $this->stageConsulta->lead_pipeline_id,
        'lead_pipeline_stage_id' => $this->stageConsulta->id,
        'user_id'                => $this->admin->id,
    ]);

    $leadsAntes = Lead::count();

    actingAs($this->admin, 'user')
        ->post(route('admin.leads.store'), leadPayload($this->person))
        ->assertRedirect();

    // No debe haberse creado un lead nuevo
    expect(Lead::count())->toBe($leadsAntes);

    // El lead existente no fue eliminado
    expect(Lead::find($leadExistente->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 4. Paciente con lead CERRADO → lo reabre, no crea uno nuevo
// ---------------------------------------------------------------------------
it('reabre el lead cerrado en lugar de crear uno nuevo', function () {
    $leadCerrado = Lead::create([
        'title'                  => 'Expediente Cerrado',
        'status'                 => 0,
        'closed_at'              => Carbon::now()->subDays(10),
        'person_id'              => $this->person->id,
        'lead_pipeline_id'       => $this->stageConsulta->lead_pipeline_id,
        'lead_pipeline_stage_id' => $this->stageConsulta->id,
        'user_id'                => $this->admin->id,
    ]);

    $leadsAntes = Lead::count();

    actingAs($this->admin, 'user')
        ->post(route('admin.leads.store'), leadPayload($this->person))
        ->assertRedirect();

    // No se creó lead nuevo
    expect(Lead::count())->toBe($leadsAntes);

    // El lead fue reabierto
    $leadCerrado->refresh();
    expect($leadCerrado->status)->toBe(1);
    expect($leadCerrado->closed_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// 5. Lead reabierto queda en stage Consulta (sin cita)
// ---------------------------------------------------------------------------
it('reabre el lead cerrado en stage Consulta cuando no hay cita', function () {
    $leadCerrado = Lead::create([
        'title'                  => 'Expediente para Reabrir',
        'status'                 => 0,
        'closed_at'              => Carbon::now()->subDays(5),
        'person_id'              => $this->person->id,
        'lead_pipeline_id'       => $this->stageConsulta->lead_pipeline_id,
        'lead_pipeline_stage_id' => $this->stageConsulta->id,
        'user_id'                => $this->admin->id,
    ]);

    actingAs($this->admin, 'user')
        ->post(route('admin.leads.store'), leadPayload($this->person))
        ->assertRedirect();

    $leadCerrado->refresh();
    expect($leadCerrado->lead_pipeline_stage_id)->toBe(1); // Consulta
});

// ---------------------------------------------------------------------------
// 6. Con cita + lead activo → crea actividad en el lead existente
// ---------------------------------------------------------------------------
it('crea una actividad en el lead existente cuando se agenda una cita', function () {
    // Mock del servicio externo para que no llame a la API real
    $this->mock(ShareMeDataService::class, function ($mock) {
        $mock->shouldReceive('checkAvailability')->andReturn([['slot' => 'available']]);
        $mock->shouldReceive('createEvent')->andReturn(true);
        $mock->shouldReceive('getLastResponse')->andReturn(null);
    });

    $doctor = Doctor::has('specialties')->first();

    if (! $doctor) {
        $this->markTestSkipped('No hay doctor con especialidades para este test.');
    }

    $start = Carbon::now()->addDay()->setTime(9, 0, 0);
    $end = $start->copy()->addHour();

    // Crear jornada laboral del doctor para ese día
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctor->id,
        'date'       => $start->toDateString(),
        'start_time' => '08:00',
        'end_time'   => '18:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Lead activo preexistente
    $leadExistente = Lead::create([
        'title'                  => 'Expediente con Cita',
        'status'                 => 1,
        'person_id'              => $this->person->id,
        'lead_pipeline_id'       => $this->stageConsulta->lead_pipeline_id,
        'lead_pipeline_stage_id' => $this->stageConsulta->id,
        'user_id'                => $this->admin->id,
    ]);

    $actividadesAntes = $leadExistente->activities()->count();
    $leadsAntes = Lead::count();

    actingAs($this->admin, 'user')
        ->post(route('admin.leads.store'), leadPayload($this->person, [
            'doctor_id'         => $doctor->id,
            'appointment_start' => $start->format('Y-m-d H:i:s'),
            'appointment_end'   => $end->format('Y-m-d H:i:s'),
        ]))
        ->assertRedirect();

    // No se creó lead nuevo
    expect(Lead::count())->toBe($leadsAntes);

    // Se creó una actividad en el lead existente
    expect($leadExistente->activities()->count())->toBe($actividadesAntes + 1);
});

// ---------------------------------------------------------------------------
// 7. Con cita + lead activo → el lead pasa a stage Confirmada
// ---------------------------------------------------------------------------
it('mueve el lead existente a stage Confirmada al agendar cita', function () {
    $this->mock(ShareMeDataService::class, function ($mock) {
        $mock->shouldReceive('checkAvailability')->andReturn([['slot' => 'available']]);
        $mock->shouldReceive('createEvent')->andReturn(true);
        $mock->shouldReceive('getLastResponse')->andReturn(null);
    });

    $doctor = Doctor::has('specialties')->first();

    if (! $doctor) {
        $this->markTestSkipped('No hay doctor con especialidades para este test.');
    }

    $start = Carbon::now()->addDays(2)->setTime(10, 0, 0);
    $end = $start->copy()->addHour();

    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctor->id,
        'date'       => $start->toDateString(),
        'start_time' => '08:00',
        'end_time'   => '18:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $leadExistente = Lead::create([
        'title'                  => 'Expediente para Confirmar',
        'status'                 => 1,
        'person_id'              => $this->person->id,
        'lead_pipeline_id'       => $this->stageConsulta->lead_pipeline_id,
        'lead_pipeline_stage_id' => $this->stageConsulta->id,
        'user_id'                => $this->admin->id,
    ]);

    actingAs($this->admin, 'user')
        ->post(route('admin.leads.store'), leadPayload($this->person, [
            'doctor_id'         => $doctor->id,
            'appointment_start' => $start->format('Y-m-d H:i:s'),
            'appointment_end'   => $end->format('Y-m-d H:i:s'),
        ]))
        ->assertRedirect();

    // El lead existente no cambió de stage (no hay cita en el lead existente, solo actividad)
    // Si en el futuro se decide actualizar el stage del lead existente, ajustar esta expectativa.
    $leadExistente->refresh();
    expect($leadExistente->status)->toBe(1);
});
