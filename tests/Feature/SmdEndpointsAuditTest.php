<?php

/*
|--------------------------------------------------------------------------
| Auditoría de endpoints integrados con ShareMeData (SMD) y Dropbox
|--------------------------------------------------------------------------
|
| Un test (o varios) por cada endpoint del CRM que habla con SMD o Dropbox,
| de punta a punta por HTTP: request → controller → servicio → request saliente.
|
| NADA sale a internet: `preventStrayRequests()` + un router único que simula
| SMD (smd.test) y Dropbox. Cualquier URL que el router no conozca queda
| registrada en `unexpected` y el test falla en afterEach.
|
| Los defectos conocidos que todavía NO están corregidos viven aparte, en
| SmdAuditKnownBugsTest.php, para que este archivo quede en verde y sirva como
| red de regresión.
|
| Ver planning/13-auditoria-endpoints-smd/00.informe.md
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Jobs\ProcessDropboxNotification;
use Webkul\Admin\Jobs\SyncSmdAppointmentsJob;
use Webkul\Admin\Support\SmdSyncState;

/*
|--------------------------------------------------------------------------
| Setup
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->smd = auditBootFakes();

    // Domingo próximo: es cuando corre la batería contra producción, así que
    // también es el día que se simula acá (y valida que no haya nada atado a
    // días hábiles en la lógica de citas).
    $this->day = Carbon::now(config('app.timezone'))->next(Carbon::SUNDAY)->startOfDay();

    Cache::forget(SyncSmdAppointmentsJob::LOCK_KEY);
    Cache::forget(SmdSyncState::LAST_FINISHED_KEY);
});

afterEach(function () {
    expect($this->smd['unexpected'])->toBe([], 'Hubo requests salientes no simulados');
});

/*
|--------------------------------------------------------------------------
| 1. Webhook de Dropbox  (GET|POST /api/webhooks/dropbox)
|--------------------------------------------------------------------------
*/

describe('webhook de Dropbox', function () {
    it('responde el challenge del handshake tal cual, como text/plain', function () {
        $this->get('/api/webhooks/dropbox?challenge=abc123')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('abc123', false);
    });

    it('rechaza un POST sin firma o con firma incorrecta y no despacha el job', function () {
        Queue::fake();

        $this->postJson('/api/webhooks/dropbox', ['list_folder' => []])->assertStatus(403);

        $this->call('POST', '/api/webhooks/dropbox', [], [], [], [
            'HTTP_X-Dropbox-Signature' => 'firma-falsa',
            'CONTENT_TYPE'             => 'application/json',
        ], '{"list_folder":{}}')->assertStatus(403);

        Queue::assertNotPushed(ProcessDropboxNotification::class);
    });

    it('acepta la firma HMAC válida y despacha ProcessDropboxNotification', function () {
        Queue::fake();
        $body = '{"list_folder":{"accounts":["dbid:x"]}}';

        $this->call('POST', '/api/webhooks/dropbox', [], [], [], [
            'HTTP_X-Dropbox-Signature' => hash_hmac('sha256', $body, 'dropbox-secret'),
            'CONTENT_TYPE'             => 'application/json',
        ], $body)->assertOk()->assertJson(['ok' => true]);

        Queue::assertPushed(ProcessDropboxNotification::class);
    });

    it('de punta a punta: webhook → job → lista cambios → baja el JSON → crea lead + cita', function () {
        $doctorId = auditDoctor();
        $doctorUid = DB::table('doctors')->where('id', $doctorId)->value('unique_id');
        $externalId = 'audit-e2e-'.uniqid();
        $start = $this->day->copy()->setTime(10, 0)->utc();

        $file = fileEntry($this->day->toDateString(), '1000', $externalId);
        $this->smd['dropbox_changes'] = ['entries' => [$file], 'cursor' => 'cursor-2', 'has_more' => false];
        $this->smd['dropbox_files'] = [$file['path_lower'] => smdEvent($externalId, [
            'startDate'   => $start->format('Y-m-d\TH:i:s.000\Z'),
            'endDate'     => $start->copy()->addMinutes(30)->format('Y-m-d\TH:i:s.000\Z'),
            'attendances' => [
                ['_id' => 'pat-'.$externalId, 'name' => 'Ana', 'lastName' => 'Audit '.uniqid(), 'type' => 'patient', 'phone' => '7'.rand(1000000, 9999999)],
                ['_id' => $doctorUid, 'name' => 'Dr', 'lastName' => 'Audit'],
            ],
        ])];

        DB::table('settings')->updateOrInsert(['key' => 'dropbox_cursor'], ['value' => 'cursor-1', 'updated_at' => now()]);

        $body = '{"list_folder":{"accounts":["dbid:x"]}}';
        $this->call('POST', '/api/webhooks/dropbox', [], [], [], [
            'HTTP_X-Dropbox-Signature' => hash_hmac('sha256', $body, 'dropbox-secret'),
            'CONTENT_TYPE'             => 'application/json',
        ], $body)->assertOk();

        $synced = DB::table('smd_synced_events')->where('external_id', $externalId)->first();

        expect($synced)->not->toBeNull()
            ->and($synced->lead_id)->not->toBeNull()
            ->and($synced->activity_id)->not->toBeNull();

        // El médico se identificó por attendances[]._id, no por el owner.
        expect(DB::table('doctor_activities')->where('activity_id', $synced->activity_id)->pluck('doctor_id')->all())
            ->toBe([$doctorId]);

        // 10:00 de La Paz = 14:00 UTC: la hora se guarda local.
        expect(Carbon::parse(DB::table('activities')->where('id', $synced->activity_id)->value('schedule_from'))->format('H:i'))
            ->toBe('10:00');

        // El cursor avanzó.
        expect(DB::table('settings')->where('key', 'dropbox_cursor')->value('value'))->toBe('cursor-2');
    });
});

/*
|--------------------------------------------------------------------------
| 2. Webhook de ShareMeData  (POST /api/webhooks/sharemedata)
|--------------------------------------------------------------------------
*/

describe('webhook de ShareMeData', function () {
    beforeEach(function () {
        config(['services.sharemedata.webhook_secret' => 'smd-secret']);

        $this->payload = [
            '_id'       => 'smd-wh-'.uniqid(),
            'physician' => ['_id' => 'smd-wh-doc-'.uniqid(), 'name' => 'Xqzw'.uniqid(), 'lastName' => 'Webhook'],
            'patient'   => ['name' => 'Yqzw'.uniqid(), 'lastName' => 'Paciente', 'phone' => '7'.rand(1000000, 9999999)],
            'slot'      => [
                'start' => $this->day->copy()->setTime(9, 0)->format('Y-m-d\TH:i:sP'),
                'end'   => $this->day->copy()->setTime(9, 30)->format('Y-m-d\TH:i:sP'),
            ],
        ];
    });

    it('rechaza con 401 un token ausente o incorrecto', function () {
        $this->postJson('/api/webhooks/sharemedata', $this->payload)->assertStatus(401);

        $this->postJson('/api/webhooks/sharemedata', $this->payload, ['X-Webhook-Secret' => 'otro'])
            ->assertStatus(401);
    });

    it('valida los campos obligatorios', function () {
        $this->postJson('/api/webhooks/sharemedata', ['slot' => []], ['X-Webhook-Secret' => 'smd-secret'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['physician._id', 'patient.phone', 'slot.start', 'slot.end']);
    });

    it('con token válido crea paciente, lead y cita', function () {
        $response = $this->postJson('/api/webhooks/sharemedata', $this->payload, ['X-Webhook-Secret' => 'smd-secret'])
            ->assertStatus(201)
            ->assertJson(['success' => true]);

        expect(DB::table('activities')->find($response->json('activity_id')))->not->toBeNull()
            ->and(DB::table('leads')->find($response->json('lead_id')))->not->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| 3. Control de sync desde la UI  (admin/activities/smd/*)
|--------------------------------------------------------------------------
*/

describe('control de sincronización (UI)', function () {
    beforeEach(fn () => test()->actingAs(getDefaultAdmin()));

    it('el sync manual encola el job y un segundo intento devuelve 409 mientras corre', function () {
        Queue::fake();

        $this->postJson(route('admin.activities.smd.sync'))->assertStatus(202)->assertJson(['state' => 'running']);
        $this->postJson(route('admin.activities.smd.sync'))->assertStatus(409);

        Queue::assertPushed(SyncSmdAppointmentsJob::class, 1);
    });

    it('el auto-sync se saltea si la sincronización está pausada', function () {
        Queue::fake();
        SmdSyncState::setPaused(true);

        $this->postJson(route('admin.activities.smd.sync'), ['auto' => true])
            ->assertOk()
            ->assertJson(['state' => 'paused', 'skipped' => true]);

        Queue::assertNothingPushed();
    });

    it('pausa y reanuda, y el status lo refleja', function () {
        $this->postJson(route('admin.activities.smd.pause'), ['paused' => true])->assertOk()->assertJson(['paused' => true]);
        $this->getJson(route('admin.activities.smd.sync.status'))->assertOk()->assertJson(['paused' => true]);

        $this->postJson(route('admin.activities.smd.pause'), ['paused' => false])->assertOk();
        $this->getJson(route('admin.activities.smd.sync.status'))->assertJson(['paused' => false]);
    });

    it('el job de la UI corre el sync contra Dropbox y publica el resumen', function () {
        $externalId = 'audit-ui-'.uniqid();
        $file = fileEntry(now()->toDateString(), '0900', $externalId);
        $this->smd['dropbox_folders'] = [now()->toDateString() => [$file]];
        $this->smd['dropbox_files'] = [$file['path_lower'] => smdEvent($externalId, ['status' => 'CANCELED'])];

        (new SyncSmdAppointmentsJob(1))->handle(app(\Webkul\Admin\Services\SmdAppointmentSyncService::class));

        expect(Cache::get(SyncSmdAppointmentsJob::STATUS_KEY)['state'])->toBe('done')
            ->and(Cache::get(SyncSmdAppointmentsJob::STATUS_KEY)['summary']['cancelados'])->toBe(1)
            ->and(Cache::has(SyncSmdAppointmentsJob::LOCK_KEY))->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| 4. Pacientes ↔ SMD  (admin/contacts/persons/*)
|--------------------------------------------------------------------------
*/

describe('pacientes vinculados a SMD', function () {
    beforeEach(fn () => test()->actingAs(getDefaultAdmin()));

    it('search-smd exige al menos 3 caracteres y no llama a SMD', function () {
        $this->getJson(route('admin.contacts.persons.search_smd', ['q' => '12']))
            ->assertOk()
            ->assertJson(['found' => false]);

        expect(auditSent('/patients'))->toBe([]);
    });

    it('search-smd busca por teléfono, cae a CI y devuelve la ficha', function () {
        $this->smd['search'] = fn (string $where) => str_starts_with($where, 'personID=')
            ? [['_id' => 'smd-ci-1', 'name' => 'Rosa', 'lastName' => 'Audit', 'personID' => '1234567', 'birthday' => '1990-05-01T00:00:00.000Z']]
            : [];

        $this->getJson(route('admin.contacts.persons.search_smd', ['q' => '1234567']))
            ->assertOk()
            ->assertJson(['found' => true, 'smd_id' => 'smd-ci-1', 'ci' => '1234567', 'birthday' => '1990-05-01']);

        // Primero probó por teléfono, después por CI.
        $wheres = collect(auditSent('/patients'))->map(fn ($r) => urldecode(parse_url($r->url(), PHP_URL_QUERY)))->implode(' | ');
        expect($wheres)->toContain('phone=')->toContain('personID=');
    });

    it('sync-smd devuelve 404 si el paciente no existe y 422 si no tiene teléfono', function () {
        $this->postJson(route('admin.contacts.persons.sync_smd', 999999999))->assertStatus(404);

        $sinTelefono = auditPerson(['contact_numbers' => []]);
        $this->postJson(route('admin.contacts.persons.sync_smd', $sinTelefono->id))->assertStatus(422);
    });

    it('sync-smd adopta el paciente que SMD ya tiene con ese teléfono', function () {
        $person = auditPerson();
        $this->smd['search'] = fn (string $where) => str_starts_with($where, 'phone=') ? [['_id' => 'smd-found-1']] : [];

        $this->postJson(route('admin.contacts.persons.sync_smd', $person->id))->assertOk();

        expect(DB::table('persons')->where('id', $person->id)->value('smd_patient_id'))->toBe('smd-found-1');
        expect(auditSent('/patients/user'))->toBe([]);
    });

    it('sync-smd crea el paciente en SMD si no existe', function () {
        $person = auditPerson(['name' => 'Lucia Audit Creada']);
        $this->smd['createPatient'] = ['_id' => 'smd-new-1'];

        $this->postJson(route('admin.contacts.persons.sync_smd', $person->id))->assertOk();

        expect(DB::table('persons')->where('id', $person->id)->value('smd_patient_id'))->toBe('smd-new-1');

        $sent = auditSent('/patients/user')[0]->data();
        expect($sent['name'])->toBe('Lucia')->and($sent['lastName'])->toBe('Audit Creada');
    });

    it('sync-smd devuelve 422 si SMD rechaza el alta', function () {
        $person = auditPerson();
        $this->smd['createPatient'] = Http::response(['message' => 'boom'], 500);

        $this->postJson(route('admin.contacts.persons.sync_smd', $person->id))->assertStatus(422);
        expect(DB::table('persons')->where('id', $person->id)->value('smd_patient_id'))->toBeNull();
    });

    it('smd-data distingue no vinculado, vinculado y vinculado-pero-inexistente (stale)', function () {
        $suelto = auditPerson();
        $this->getJson(route('admin.contacts.persons.smd_data', $suelto->id))->assertJson(['linked' => false]);

        $vinculado = auditPerson(['smd_patient_id' => 'smd-ok-1']);
        $this->smd['patient'] = fn (string $id) => $id === 'smd-ok-1'
            ? ['_id' => 'smd-ok-1', 'name' => 'Eva', 'lastName' => 'Audit', 'personID' => '999']
            : Http::response(['message' => 'not found'], 404);

        $this->getJson(route('admin.contacts.persons.smd_data', $vinculado->id))
            ->assertJson(['linked' => true, 'stale' => false, 'name' => 'Eva Audit', 'ci' => '999']);

        $huerfano = auditPerson(['smd_patient_id' => 'smd-borrado']);
        $this->getJson(route('admin.contacts.persons.smd_data', $huerfano->id))
            ->assertJson(['linked' => true, 'stale' => true]);
    });

    it('smd-data exige permiso para ver contactos y no consulta SMD sin él (AUD-10)', function () {
        $role = \Webkul\User\Models\Role::create([
            'name'            => 'Sin contactos '.uniqid(),
            'description'     => 'audit',
            'permission_type' => 'custom',
            'permissions'     => ['dashboard'],
        ]);

        $user = \Webkul\User\Models\User::create([
            'name'     => 'Audit sin permisos '.uniqid(),
            'email'    => 'audit'.uniqid().'@test.com',
            'password' => bcrypt('secret'),
            'role_id'  => $role->id,
            'status'   => 1,
        ]);

        $person = auditPerson(['smd_patient_id' => '6a3d505549b7260008a434d5']);

        test()->actingAs($user, 'user')
            ->getJson(route('admin.contacts.persons.smd_data', $person->id))
            ->assertStatus(403);

        expect(auditSent('/patients'))->toBe([]);
    });

    it('link-smd valida, rechaza un id inexistente en SMD y vincula uno válido', function () {
        $person = auditPerson();

        $this->postJson(route('admin.contacts.persons.link_smd', $person->id), [])->assertStatus(422);

        $this->postJson(route('admin.contacts.persons.link_smd', $person->id), ['smd_patient_id' => 'no-existe'])
            ->assertStatus(422);

        $this->smd['patient'] = fn (string $id) => ['_id' => $id, 'name' => 'X'];
        $this->postJson(route('admin.contacts.persons.link_smd', $person->id), ['smd_patient_id' => '6a3d505549b7260008a434d5'])
            ->assertOk();

        expect(DB::table('persons')->where('id', $person->id)->value('smd_patient_id'))->toBe('6a3d505549b7260008a434d5');
    });
});

/*
|--------------------------------------------------------------------------
| 5. Disponibilidad real  (GET /api/doctors/{id}/available-slots)
|--------------------------------------------------------------------------
*/

describe('disponibilidad real del doctor', function () {
    it('exige autenticación Sanctum', function () {
        $this->getJson('/api/doctors/1/available-slots?date='.$this->day->toDateString())->assertStatus(401);
    });

    it('valida parámetros y doctores inexistentes', function () {
        actingAsSanctumAuthenticatedAdmin();

        $this->getJson('/api/doctors/1/available-slots')->assertStatus(422);
        $this->getJson('/api/doctors/1/available-slots?date=2020-01-01')->assertStatus(422);
        $this->getJson('/api/doctors/999999999/available-slots?date='.$this->day->toDateString())->assertStatus(404);
    });

    it('cruza SMD con la jornada local y devuelve source=smd', function () {
        actingAsSanctumAuthenticatedAdmin();
        $doctorId = auditDoctor();
        $uid = DB::table('doctors')->where('id', $doctorId)->value('unique_id');
        auditShift($doctorId, $this->day, '09:00', '12:00');

        // SMD dice libre de 08:00 a 10:00; la jornada local arranca a las 09:00.
        $this->smd['availability'] = auditSmdAvailability($uid, $this->day->copy()->setTime(8, 0), $this->day->copy()->setTime(10, 0));

        $res = $this->getJson("/api/doctors/{$doctorId}/available-slots?date={$this->day->toDateString()}&days=1")
            ->assertOk()
            ->assertJson(['source' => 'smd', 'degraded' => false]);

        expect($res->json('schedule.0.slots'))->toBe([
            ['start_time' => '09:00', 'end_time' => '10:00', 'status' => 'available'],
        ]);

        // A SMD se le pidió por este doctor y en hora de La Paz.
        $q = urldecode(parse_url(auditSent('/schedule/availability')[0]->url(), PHP_URL_QUERY));
        expect($q)->toContain('-04:00')->toContain('timeZone=America/La_Paz');
    });

    it('degrada a local con reason=doctor_unlinked si el doctor no tiene unique_id', function () {
        actingAsSanctumAuthenticatedAdmin();
        $doctorId = auditDoctor(['unique_id' => null]);

        $this->getJson("/api/doctors/{$doctorId}/available-slots?date={$this->day->toDateString()}&days=1")
            ->assertOk()
            ->assertJson(['source' => 'local', 'degraded' => true, 'reason' => 'doctor_unlinked']);

        expect(auditSent('/schedule/availability'))->toBe([]);
    });

    it('degrada a local con reason=smd_unavailable si SMD responde 5xx', function () {
        actingAsSanctumAuthenticatedAdmin();
        $doctorId = auditDoctor();
        $this->smd['availability'] = Http::response(['message' => 'down'], 503);

        $this->getJson("/api/doctors/{$doctorId}/available-slots?date={$this->day->toDateString()}&days=1")
            ->assertOk()
            ->assertJson(['source' => 'local', 'degraded' => true, 'reason' => 'smd_unavailable']);
    });
});

/*
|--------------------------------------------------------------------------
| 6. Crear cita  (POST /api/v1/activities  y  admin/activities/appointments/create)
|--------------------------------------------------------------------------
*/

describe('crear cita (empuja createEvent a SMD)', function () {
    beforeEach(function () {
        $this->doctorId = auditDoctor();
        $this->doctorUid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
        $this->from = $this->day->copy()->setTime(10, 0);
        $this->to = $this->from->copy()->addMinutes(30);
        $this->person = auditPerson(['smd_patient_id' => 'smd-pat-'.uniqid()]);

        auditShift($this->doctorId, $this->day);
        $this->smd['availability'] = auditSmdAvailability($this->doctorUid, $this->from, $this->to);
    });

    it('API v1: exige doctor y paciente para un meeting', function () {
        actingAsSanctumAuthenticatedAdmin();

        $this->postJson('/api/v1/activities', [
            'type'          => 'meeting',
            'schedule_from' => $this->from->format('Y-m-d H:i:s'),
            'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
        ])->assertStatus(422);
    });

    it('API v1: fuera de la jornada del doctor responde 422 sin llamar a SMD', function () {
        actingAsSanctumAuthenticatedAdmin();
        $noche = $this->day->copy()->setTime(21, 0);

        $this->postJson('/api/v1/activities', [
            'type'          => 'meeting',
            'schedule_from' => $noche->format('Y-m-d H:i:s'),
            'schedule_to'   => $noche->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            'participants'  => ['doctors' => [$this->doctorId], 'persons' => [$this->person->id]],
        ])->assertStatus(422);

        expect(auditSent('smd.test'))->toBe([]);
    });

    it('API v1: crea el evento en SMD y la cita local queda vinculada al external_id', function () {
        actingAsSanctumAuthenticatedAdmin();
        $eventId = 'evt-api-'.uniqid();
        $this->smd['createEvent'] = auditCreateEventResponse($eventId);

        $res = $this->postJson('/api/v1/activities', [
            'type'          => 'meeting',
            'schedule_from' => $this->from->format('Y-m-d H:i:s'),
            'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
            'comment'       => 'control',
            'participants'  => ['doctors' => [$this->doctorId], 'persons' => [$this->person->id]],
        ])->assertOk();

        $activityId = $res->json('data.activity_id');

        expect(DB::table('smd_synced_events')->where('external_id', $eventId)->value('activity_id'))->toBe($activityId);

        $sent = auditSent('/schedule/createEvent')[0]->data();
        expect($sent['physician']['_id'])->toBe($this->doctorUid)
            ->and($sent['slot']['start'])->toBe($this->from->format('Y-m-d\T10:00:00').'-04:00');

        // personID es la cédula para SMD: nunca el _id del paciente (AUD-11).
        expect($sent['patient']['personID'])->not->toBe($this->person->smd_patient_id);

        // El paciente se asocia con el PATCH posterior (createEvent no lo hace).
        $patch = auditSent('/events/'.$eventId)[0];
        $patients = collect($patch->data()['attendances'])->where('type', 'patient')->pluck('_id')->all();
        expect($patch->method())->toBe('PATCH')
            ->and($patients)->toBe([$this->person->smd_patient_id])
            ->and(collect($patch->data()['attendances'])->where('type', 'physician')->pluck('_id')->all())->toBe([$this->doctorUid]);

        // Etapa "Cita confirmada", no la de cancelada.
        $stage = DB::table('leads')->where('id', $res->json('data.lead_id'))->value('lead_pipeline_stage_id');
        expect((int) $stage)->not->toBe((int) config('smd.stage_map.cancelled'));
    });

    it('API v1: manda la cédula del paciente como personID (AUD-11)', function () {
        actingAsSanctumAuthenticatedAdmin();
        $attr = DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id');

        if (! $attr) {
            $this->markTestSkipped('No existe el atributo ci_paciente en esta BD.');
        }

        DB::table('attribute_values')->insert([
            'attribute_id' => $attr,
            'entity_id'    => $this->person->id,
            'entity_type'  => 'persons',
            'text_value'   => '8765432',
        ]);

        $this->postJson('/api/v1/activities', [
            'type'          => 'meeting',
            'schedule_from' => $this->from->format('Y-m-d H:i:s'),
            'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
            'participants'  => ['doctors' => [$this->doctorId], 'persons' => [$this->person->id]],
        ])->assertOk();

        expect(auditSent('/schedule/createEvent')[0]->data()['patient']['personID'])->toBe('8765432');
    });

    it('API v1: sin disponibilidad en SMD responde 422 y no crea nada local', function () {
        actingAsSanctumAuthenticatedAdmin();
        $this->smd['availability'] = [];
        $antes = DB::table('activities')->count();

        $this->postJson('/api/v1/activities', [
            'type'          => 'meeting',
            'schedule_from' => $this->from->format('Y-m-d H:i:s'),
            'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
            'participants'  => ['doctors' => [$this->doctorId], 'persons' => [$this->person->id]],
        ])->assertStatus(422);

        expect(DB::table('activities')->count())->toBe($antes)
            ->and(auditSent('/schedule/createEvent'))->toBe([]);
    });

    it('API v1: si createEvent falla en SMD responde 422 y no crea nada local', function () {
        actingAsSanctumAuthenticatedAdmin();
        $this->smd['createEvent'] = Http::response(['message' => 'slot ocupado'], 409);
        $antes = DB::table('activities')->count();

        $this->postJson('/api/v1/activities', [
            'type'          => 'meeting',
            'schedule_from' => $this->from->format('Y-m-d H:i:s'),
            'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
            'participants'  => ['doctors' => [$this->doctorId], 'persons' => [$this->person->id]],
        ])->assertStatus(422);

        expect(DB::table('activities')->count())->toBe($antes);
    });

    it('API v1: un paciente sin smd_patient_id se busca/crea en SMD antes del evento', function () {
        actingAsSanctumAuthenticatedAdmin();
        $nuevo = auditPerson();
        $this->smd['createPatient'] = ['_id' => 'smd-pat-alta-1'];

        $this->postJson('/api/v1/activities', [
            'type'          => 'meeting',
            'schedule_from' => $this->from->format('Y-m-d H:i:s'),
            'schedule_to'   => $this->to->format('Y-m-d H:i:s'),
            'participants'  => ['doctors' => [$this->doctorId], 'persons' => [$nuevo->id]],
        ])->assertOk();

        expect(DB::table('persons')->where('id', $nuevo->id)->value('smd_patient_id'))->toBe('smd-pat-alta-1');

        // El paciente recién dado de alta queda asociado al evento por el PATCH.
        $patch = collect(auditSent('/events/'))->first(fn ($r) => $r->method() === 'PATCH');
        expect(collect($patch->data()['attendances'])->where('type', 'patient')->pluck('_id')->all())->toBe(['smd-pat-alta-1']);
    });

    it('admin: appointments/create crea la cita sincronizada con SMD', function () {
        test()->actingAs(getDefaultAdmin());

        $this->postJson(route('admin.activities.appointments.store'), [
            'person'           => ['id' => $this->person->id, 'name' => $this->person->name],
            'doctor_id'        => $this->doctorId,
            'date'             => $this->day->toDateString(),
            'start_time'       => '10:00',
            'duration_minutes' => 30,
            'reason'           => 'control',
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        expect(auditSent('/schedule/createEvent'))->toHaveCount(1);
    });
});

/*
|--------------------------------------------------------------------------
| 7. Editar / cambiar estado  (admin/activities/appointments/{id}/*)
|--------------------------------------------------------------------------
*/

describe('editar cita (empuja PATCH a SMD)', function () {
    beforeEach(function () {
        test()->actingAs(getDefaultAdmin());
        $this->doctorId = auditDoctor();
        $this->person = auditPerson(['smd_patient_id' => 'smd-pat-'.uniqid()]);
        $this->from = $this->day->copy()->setTime(11, 0);
    });

    it('cancelar desde el calendario manda status CANCELED a SMD y mueve el lead', function () {
        $eventId = 'evt-st-'.uniqid();
        $ids = auditAppointment($this->doctorId, $this->person->id, $this->from, $eventId);

        $this->postJson(route('admin.activities.appointments.status', $ids['activity_id']), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJson(['smd' => ['synced' => true]]);

        $patch = auditSent('/events/'.$eventId)[0];
        expect($patch->method())->toBe('PATCH')
            ->and($patch->data()['status'])->toBe(config('smd.smd_status.cancelled'))
            ->and((int) DB::table('leads')->where('id', $ids['lead_id'])->value('lead_pipeline_stage_id'))
            ->toBe((int) config('smd.stage_map.cancelled'));
    });

    it('reprogramar manda el horario nuevo a SMD en UTC', function () {
        $eventId = 'evt-up-'.uniqid();
        $ids = auditAppointment($this->doctorId, $this->person->id, $this->from, $eventId);

        $this->postJson(route('admin.activities.appointments.update', $ids['activity_id']), [
            'date'             => $this->day->toDateString(),
            'start_time'       => '15:00',
            'duration_minutes' => 45,
        ])->assertOk();

        $body = auditSent('/events/'.$eventId)[0]->data();
        expect($body['startDate'])->toBe($this->day->toDateString().'T19:00:00.000Z')
            ->and($body['endDate'])->toBe($this->day->toDateString().'T19:45:00.000Z');
    });

    it('una cita no vinculada a SMD se edita local sin llamar a SMD', function () {
        $ids = auditAppointment($this->doctorId, $this->person->id, $this->from);

        $this->postJson(route('admin.activities.appointments.status', $ids['activity_id']), ['status' => 'completed'])
            ->assertOk()
            ->assertJson(['smd' => ['synced' => false]]);

        expect(auditSent('/events/'))->toBe([]);
    });

    it('si SMD rechaza el PATCH la edición local se conserva e informa el motivo', function () {
        $eventId = 'evt-ko-'.uniqid();
        $ids = auditAppointment($this->doctorId, $this->person->id, $this->from, $eventId);
        $this->smd['updateEvent'] = Http::response(['message' => 'evento inexistente'], 404);

        $this->postJson(route('admin.activities.appointments.status', $ids['activity_id']), ['status' => 'no_show'])
            ->assertOk()
            ->assertJson(['smd' => ['synced' => false, 'reason' => 'evento inexistente']]);

        expect((int) DB::table('leads')->where('id', $ids['lead_id'])->value('lead_pipeline_stage_id'))
            ->toBe((int) config('smd.stage_map.no_show'));
    });
});
