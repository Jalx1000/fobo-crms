<?php

/*
|--------------------------------------------------------------------------
| Defectos conocidos de la integración SMD / Dropbox (auditoría 2026-10)
|--------------------------------------------------------------------------
|
| Cada test describe el comportamiento CORRECTO. Nacieron fallando (uno por
| defecto encontrado) y al 2026-10-12 están TODOS corregidos: ahora son la red
| de regresión de esos bugs. Si uno vuelve a rojo, se reintrodujo el defecto.
|
| Ver planning/13-auditoria-endpoints-smd/00.informe.md (mismos códigos AUD-xx).
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Admin\Services\SmdAppointmentSyncService;

beforeEach(function () {
    $this->smd = auditBootFakes();
    $this->sync = app(SmdAppointmentSyncService::class);
    $this->day = Carbon::now(config('app.timezone'))->next(Carbon::SUNDAY)->startOfDay();
});

/** Payload de cita con paciente identificable y versión ($v) creciente. */
function bugEvent(string $id, int $v, array $overrides = []): array
{
    return smdEvent($id, array_merge([
        '__v'         => $v,
        'updated_at'  => Carbon::parse('2026-10-01T10:00:00Z')->addMinutes($v)->format('Y-m-d\TH:i:s.000\Z'),
        'attendances' => [[
            '_id'      => 'pat-'.$id,
            'name'     => 'Bug',
            'lastName' => 'Paciente '.$id,
            'type'     => 'patient',
            'phone'    => '7'.substr(crc32($id), 0, 7),
        ]],
    ], $overrides));
}

it('AUD-01: el webhook de SMD rechaza los requests si no hay secreto configurado', function () {
    config(['services.sharemedata.webhook_secret' => null]);

    $this->postJson('/api/webhooks/sharemedata', [
        'physician' => ['_id' => 'x'],
        'patient'   => ['phone' => '70000000'],
        'slot'      => ['start' => '2026-10-11T09:00:00-04:00', 'end' => '2026-10-11T09:30:00-04:00'],
    ])->assertStatus(401);
});

it('AUD-02: el webhook de SMD es idempotente (el mismo _id no duplica la cita)', function () {
    config(['services.sharemedata.webhook_secret' => 's']);

    $payload = [
        '_id'       => 'smd-dup-'.uniqid(),
        'physician' => ['_id' => 'smd-dup-doc-'.uniqid(), 'name' => 'Zqdup'.uniqid(), 'lastName' => 'Doc'],
        'patient'   => ['name' => 'Zqpac'.uniqid(), 'lastName' => 'Dup', 'phone' => '7'.rand(1000000, 9999999)],
        'slot'      => ['start' => '2026-10-11T09:00:00-04:00', 'end' => '2026-10-11T09:30:00-04:00'],
    ];

    $first = $this->postJson('/api/webhooks/sharemedata', $payload, ['X-Webhook-Secret' => 's'])->json('activity_id');
    $second = $this->postJson('/api/webhooks/sharemedata', $payload, ['X-Webhook-Secret' => 's'])->json('activity_id');

    expect($second)->toBe($first);
});

it('AUD-03: el webhook de Dropbox no loguea la firma HMAC esperada', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->context;
    });

    $this->call('POST', '/api/webhooks/dropbox', [], [], [], [
        'HTTP_X-Dropbox-Signature' => 'firma-falsa',
        'CONTENT_TYPE'             => 'application/json',
    ], '{}')->assertStatus(403);

    $filtrada = collect($logged)->contains(fn ($ctx) => in_array(hash_hmac('sha256', '{}', 'dropbox-secret'), $ctx, true));
    expect($filtrada)->toBeFalse();
});

it('AUD-04: un archivo que no se procesó por lock ocupado NO se marca como procesado', function () {
    $id = 'bug-lock-'.uniqid();
    $file = fileEntry($this->day->toDateString(), '0900', $id);
    $this->smd['dropbox_files'] = [$file['path_lower'] => bugEvent($id, 0)];

    // Otro worker (cron o webhook) tiene tomada esta cita. Sin espera, para
    // que el test no tarde los 10 s de lock_wait_seconds.
    config(['smd.dropbox.lock_wait_seconds' => 0]);
    $lock = Cache::lock('smd:event:'.$id, 60);
    $lock->get();

    $summary = $this->sync->emptySummary();
    $this->sync->processEntries([$file], $summary);
    $lock->release();

    expect($summary['en_proceso'])->toBe(1);

    // Si se marca, la próxima corrida lo salta y esta versión no se aplica nunca.
    expect(DB::table('smd_processed_files')->where('path', $file['path_lower'])->exists())->toBeFalse()
        ->and(DB::table('smd_synced_events')->where('external_id', $id)->exists())->toBeFalse();
});

it('AUD-05: una cita cancelada en SMD guarda closed_at en el lead', function () {
    $id = 'bug-closed-'.uniqid();

    $this->sync->processPayload(bugEvent($id, 0));
    $this->sync->processPayload(bugEvent($id, 1, ['status' => 'CANCELED']));

    $leadId = DB::table('smd_synced_events')->where('external_id', $id)->value('lead_id');

    expect(DB::table('leads')->where('id', $leadId)->value('closed_at'))->not->toBeNull();
});

it('AUD-06: una cita reactivada en SMD deja de figurar como perdida', function () {
    $id = 'bug-react-'.uniqid();

    $this->sync->processPayload(bugEvent($id, 0));
    $this->sync->processPayload(bugEvent($id, 1, ['status' => 'CANCELED']));
    $this->sync->processPayload(bugEvent($id, 2, ['status' => '']));

    $lead = DB::table('leads')->find(DB::table('smd_synced_events')->where('external_id', $id)->value('lead_id'));

    expect((int) $lead->lead_pipeline_stage_id)->toBe((int) config('smd.stage_map.default'))
        ->and($lead->status)->not->toBe(0)
        ->and($lead->lost_reason)->toBeNull();
});

it('AUD-07: un bloqueo de agenda cancelado en SMD libera el horario del doctor', function () {
    $doctorId = auditDoctor();
    $uid = DB::table('doctors')->where('id', $doctorId)->value('unique_id');
    $id = 'bug-block-'.uniqid();

    // Sin paciente: es un bloqueo (time_off) del doctor.
    $bloqueo = fn (int $v, array $o = []) => bugEvent($id, $v, array_merge([
        'summary'     => 'Pausa',
        'attendances' => [['_id' => $uid, 'name' => 'Dr', 'lastName' => 'Audit']],
    ], $o));

    $this->sync->processPayload($bloqueo(0));
    $activityId = DB::table('smd_synced_events')->where('external_id', $id)->value('activity_id');
    expect(DB::table('activities')->where('id', $activityId)->value('type'))->toBe('time_off');

    $this->sync->processPayload($bloqueo(1, ['archived' => true]));

    expect(DB::table('activities')->where('id', $activityId)->exists())->toBeFalse();
});

it('AUD-08: un médico nuevo no se asigna a otro doctor que solo comparte el nombre de pila', function () {
    $nombre = 'Qwxzy'.substr(uniqid(), -5);
    $existente = auditDoctor(['name' => "{$nombre} Existente"]);
    $id = 'bug-name-'.uniqid();

    $this->sync->processPayload(bugEvent($id, 0, [
        'attendances' => [
            ['_id' => 'pat-'.$id, 'name' => 'Bug', 'lastName' => 'Pac', 'type' => 'patient', 'phone' => '7'.rand(1000000, 9999999)],
            ['_id' => 'smd-new-doc-'.uniqid(), 'name' => $nombre, 'lastName' => 'Distinto', 'type' => 'physician'],
        ],
    ]));

    $activityId = DB::table('smd_synced_events')->where('external_id', $id)->value('activity_id');

    expect(DB::table('doctor_activities')->where('activity_id', $activityId)->pluck('doctor_id')->all())
        ->not->toContain($existente);
});

it('AUD-08: el nombre aproximado sigue encontrando al doctor (título y segundo apellido)', function () {
    // El reverso del caso anterior: el criterio nuevo no debe crear duplicados
    // cuando SMD manda "Dra. Nombre Apellido" y el CRM tiene el nombre completo.
    $base = 'Zqtok'.substr(uniqid(), -5);
    $existente = auditDoctor(['name' => "{$base} Rojasx Godoyx"]);
    $id = 'bug-name-ok-'.uniqid();

    $this->sync->processPayload(bugEvent($id, 0, [
        'attendances' => [
            ['_id' => 'pat-'.$id, 'name' => 'Bug', 'lastName' => 'Pac', 'type' => 'patient', 'phone' => '7'.rand(1000000, 9999999)],
            ['_id' => 'smd-other-'.uniqid(), 'name' => "Dra. {$base}", 'lastName' => 'Rojasx', 'type' => 'physician'],
        ],
    ]));

    $activityId = DB::table('smd_synced_events')->where('external_id', $id)->value('activity_id');

    expect(DB::table('doctor_activities')->where('activity_id', $activityId)->pluck('doctor_id')->all())
        ->toBe([$existente]);
});

it('AUD-09: si borraron la actividad pero el lead sigue, la actualización recrea la cita', function () {
    $id = 'bug-orphan-'.uniqid();

    $this->sync->processPayload(bugEvent($id, 0));
    $activityId = DB::table('smd_synced_events')->where('external_id', $id)->value('activity_id');
    DB::table('activities')->where('id', $activityId)->delete();

    $this->sync->processPayload(bugEvent($id, 1, ['summary' => 'Reprogramada']));

    $nuevo = DB::table('smd_synced_events')->where('external_id', $id)->value('activity_id');
    expect(DB::table('activities')->where('id', $nuevo)->exists())->toBeTrue();
});

it('AUD-10: link-smd rechaza ids con caracteres de ruta y no los manda a SMD', function () {
    test()->actingAs(getDefaultAdmin());
    $person = auditPerson();

    $this->postJson(route('admin.contacts.persons.link_smd', $person->id), [
        'smd_patient_id' => '../calendar/specialties',
    ])->assertStatus(422);

    expect(auditSent('..'))->toBe([]);
    $this->smd['unexpected'] = [];
});

it('AUD-13: un bloqueo que vuelve cancelado y CON paciente no deja una cita desvinculada', function () {
    // Caso real del 2026-10-11: createEvent dejó la cita sin paciente (AUD-11),
    // entró como bloqueo, y el PATCH de cancelación le agregó el paciente.
    $doctorId = auditDoctor();
    $uid = DB::table('doctors')->where('id', $doctorId)->value('unique_id');
    $id = 'bug-promo-'.uniqid();

    $this->sync->processPayload(bugEvent($id, 0, [
        'attendances' => [['_id' => $uid, 'name' => 'Dr', 'lastName' => 'Audit', 'type' => 'physician']],
    ]));

    $this->sync->processPayload(bugEvent($id, 1, [
        'status'      => 'CANCELED',
        'attendances' => [
            ['_id' => 'pat-'.$id, 'name' => 'Bug', 'lastName' => 'Promo', 'phone' => '7'.rand(1000000, 9999999)],
            ['_id' => $uid, 'name' => 'Dr', 'lastName' => 'Audit'],
        ],
    ]));

    $row = DB::table('smd_synced_events')->where('external_id', $id)->first();

    // El registro tiene que apuntar a lo que realmente existe; si no, cada
    // actualización futura de este evento vuelve a crear un lead nuevo.
    expect(DB::table('activities')->where('id', $row->activity_id)->exists())->toBeTrue('smd_synced_events apunta a una actividad borrada');

    if ($row->lead_id === null) {
        expect(DB::table('leads')->where('person_id', DB::table('persons')->where('smd_patient_id', 'pat-'.$id)->value('id'))->exists())
            ->toBeFalse('Se creó un lead que no quedó vinculado al evento');
    }

    // Y si se creó un lead, tiene que quedar CANCELADO: el evento llegó cancelado.
    // En producción quedaron 3 leads activos en "Consultas" para el día siguiente.
    if ($row->lead_id !== null) {
        expect((int) DB::table('leads')->where('id', $row->lead_id)->value('lead_pipeline_stage_id'))
            ->toBe((int) config('smd.stage_map.cancelled'));
    }
});
