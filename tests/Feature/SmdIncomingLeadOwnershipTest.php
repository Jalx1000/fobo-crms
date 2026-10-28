<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Activity\Models\Activity;
use Webkul\Admin\Services\IncomingAppointmentService;
use Webkul\Lead\Models\Lead;

/**
 * Las citas que llegan de SMD por Dropbox se crean sin usuario en sesión. Antes
 * quedaban sin dueño (la cuenta "Sin asignar" se buscaba por un email que ya no
 * existía), sin origen ni tipo, y con el `summary` crudo de SMD como título
 * ("ssss"): las recepcionistas no las veían y el calendario mostraba basura.
 * Ahora se reparten entre las recepcionistas activas.
 */
function ownershipPayload(array $overrides = []): array
{
    return array_merge([
        '_id'         => uniqid('own_', true),
        'archived'    => false,
        'status'      => '',
        'startDate'   => '2026-11-12T15:00:00.000Z',
        'endDate'     => '2026-11-12T15:30:00.000Z',
        'summary'     => 'ssss',
        'attendances' => [
            [
                '_id'      => '6a0cc3d0'.uniqid(),
                'name'     => 'Danitza',
                'lastName' => 'Prueba',
                'type'     => 'patient',
                'phone'    => '5917'.rand(1000000, 9999999),
                'fullName' => 'Danitza Prueba',
            ],
            [
                '_id'      => '69d904e4'.uniqid(),
                'name'     => 'Adriana',
                'lastName' => 'Soria',
                'type'     => 'physician',
                'phone'    => '',
                'fullName' => 'Adriana Soria',
            ],
        ],
    ], $overrides);
}

function ownershipUser(int $roleId, string $name, int $status = 1): int
{
    return DB::table('users')->insertGetId([
        'name'            => $name,
        'email'           => uniqid('recep_').'@test.local',
        'password'        => bcrypt('secret'),
        'status'          => $status,
        'view_permission' => 'global',
        'role_id'         => $roleId,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

function ownershipLead(array $result): Lead
{
    return Lead::findOrFail($result['lead_id']);
}

beforeEach(function () {
    // El directorio de pacientes consulta SMD: sin respuesta, nada sale a la red.
    Http::fake(['*' => Http::response([], 200)]);

    // Un rol propio aísla el test de las recepcionistas reales de la BD.
    $this->roleName = 'Recepcionista '.uniqid();
    $this->roleId = DB::table('roles')->insertGetId([
        'name'            => $this->roleName,
        'description'     => 'test',
        'permission_type' => 'custom',
        'permissions'     => json_encode([]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    config(['smd.assignment.role' => $this->roleName]);
});

it('asigna la cita de SMD a una recepcionista activa', function () {
    $ana = ownershipUser($this->roleId, 'Ana');

    $lead = ownershipLead(app(IncomingAppointmentService::class)->processDropbox(ownershipPayload()));

    expect($lead->user_id)->toBe($ana);
});

it('reparte las citas de SMD entre las recepcionistas por turnos', function () {
    $ana = ownershipUser($this->roleId, 'Ana');
    $bea = ownershipUser($this->roleId, 'Bea');
    $service = app(IncomingAppointmentService::class);

    $owners = collect(range(1, 4))
        ->map(fn () => ownershipLead($service->processDropbox(ownershipPayload()))->user_id);

    expect($owners->countBy()->all())->toBe([$ana => 2, $bea => 2]);
});

it('no le asigna citas a una recepcionista desactivada', function () {
    ownershipUser($this->roleId, 'Inactiva', status: 0);
    $activa = ownershipUser($this->roleId, 'Activa');
    $service = app(IncomingAppointmentService::class);

    $owners = collect(range(1, 3))
        ->map(fn () => ownershipLead($service->processDropbox(ownershipPayload()))->user_id)
        ->unique()->values()->all();

    expect($owners)->toBe([$activa]);
});

it('sin recepcionistas la cita igual se crea', function () {
    $result = app(IncomingAppointmentService::class)->processDropbox(ownershipPayload());

    expect($result['lead_id'])->not->toBeNull()
        ->and($result['activity_id'])->not->toBeNull();
});

it('marca el lead con origen ShareMeData y tipo de lead', function () {
    ownershipUser($this->roleId, 'Ana');

    $lead = ownershipLead(app(IncomingAppointmentService::class)->processDropbox(ownershipPayload()));

    expect(DB::table('lead_sources')->where('id', $lead->lead_source_id)->value('name'))->toBe('ShareMeData')
        ->and((int) $lead->lead_type_id)->toBe((int) config('smd.lead_type_id'));
});

it('titula la actividad como el CRM y deja el texto de SMD en el comentario', function () {
    ownershipUser($this->roleId, 'Ana');

    $result = app(IncomingAppointmentService::class)->processDropbox(ownershipPayload());
    $activity = Activity::findOrFail($result['activity_id']);

    expect($activity->title)->toBe('CONSULTA: Danitza Prueba - Consulta')
        ->and($activity->comment)->toBe('ssss');
});

it('una modificación en SMD no pisa el título con el summary', function () {
    ownershipUser($this->roleId, 'Ana');
    $service = app(IncomingAppointmentService::class);
    $payload = ownershipPayload();

    $result = $service->processDropbox($payload);
    $existing = DB::table('smd_synced_events')->where('external_id', $payload['_id'])->first()
        ?? (object) ['activity_id' => $result['activity_id'], 'lead_id' => $result['lead_id']];

    $service->updateDropbox(array_merge($payload, ['summary' => 'otra cosa']), $existing);
    $activity = Activity::findOrFail($result['activity_id']);

    expect($activity->title)->toBe('CONSULTA: Danitza Prueba - Consulta')
        ->and($activity->comment)->toBe('otra cosa');
});

it('la modificación no le cambia la recepcionista a la cita', function () {
    $ana = ownershipUser($this->roleId, 'Ana');
    $service = app(IncomingAppointmentService::class);
    $payload = ownershipPayload();

    $result = $service->processDropbox($payload);
    ownershipUser($this->roleId, 'Bea');

    $service->updateDropbox(
        array_merge($payload, ['summary' => 'cambio']),
        (object) ['activity_id' => $result['activity_id'], 'lead_id' => $result['lead_id']],
    );

    expect(ownershipLead($result)->user_id)->toBe($ana);
});
