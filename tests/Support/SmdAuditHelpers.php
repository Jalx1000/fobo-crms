<?php

/*
| Helpers compartidos por SmdEndpointsAuditTest y SmdAuditKnownBugsTest.
| Se cargan con require_once: Pest no comparte funciones entre archivos de test.
*/

use Carbon\Carbon;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;

/**
 * Slots de SMD en bloques de 15 min con el formato real de
 * `/schedule/availability`: [{physician, slots: [{ "Y-m-d": [{start,end}] }]}].
 */
function auditSmdAvailability(string $physicianId, Carbon $from, Carbon $to): array
{
    $intervals = [];

    for ($c = $from->copy(); $c->lt($to); $c->addMinutes(15)) {
        $intervals[] = [
            'start' => $c->format('Y-m-d\TH:i:sP'),
            'end'   => $c->copy()->addMinutes(15)->format('Y-m-d\TH:i:sP'),
        ];
    }

    return [[
        'physician' => ['_id' => $physicianId, 'name' => 'Audit', 'lastName' => 'Doctor'],
        'slots'     => [[$from->toDateString() => $intervals]],
    ]];
}

/**
 * Router único para SMD + Dropbox. Lee el estado en cada llamada, así un test
 * puede cambiar una respuesta (p. ej. createEvent → 500) sin re-fakear.
 */
function auditRouter(ArrayObject $s): Closure
{
    $reply = fn ($value) => $value instanceof GuzzleHttp\Promise\PromiseInterface
        ? $value
        : Http::response($value, 200);

    return function (HttpRequest $r) use ($s, $reply) {
        $url = $r->url();
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $method = strtoupper($r->method());

        // ── Dropbox ─────────────────────────────────────────────────────────
        if (str_contains($url, 'oauth2/token')) {
            return Http::response(['access_token' => 'tok'], 200);
        }

        if (str_ends_with($path, '/files/list_folder/continue')) {
            return $reply($s['dropbox_changes']);
        }

        if (str_ends_with($path, '/files/list_folder')) {
            $date = basename($r->data()['path'] ?? '');

            return Http::response([
                'entries'  => $s['dropbox_folders'][$date] ?? [],
                'cursor'   => 'cursor-folder',
                'has_more' => false,
            ], 200);
        }

        if (str_ends_with($path, '/files/download')) {
            $arg = json_decode($r->header('Dropbox-API-Arg')[0] ?? '{}', true);

            return Http::response(json_encode($s['dropbox_files'][$arg['path'] ?? ''] ?? []), 200);
        }

        // ── ShareMeData ─────────────────────────────────────────────────────
        if (str_contains($url, 'smd.test')) {
            if (str_ends_with($path, '/specialties')) {
                return $reply($s['specialties']);
            }

            if (str_ends_with($path, '/schedule/availability')) {
                // Un closure recibe el request: permite responder distinto por especialidad.
                return $reply($s['availability'] instanceof Closure ? ($s['availability'])($r) : $s['availability']);
            }

            if (str_ends_with($path, '/schedule/createEvent')) {
                return $reply($s['createEvent']);
            }

            if (str_contains($path, '/events/') && $method === 'PATCH') {
                return $reply($s['updateEvent']);
            }

            if (str_ends_with($path, '/patients/user') && $method === 'POST') {
                return $reply($s['createPatient']);
            }

            if (preg_match('#/patients/([^/]+)$#', $path, $m) && $method === 'GET') {
                return $reply(($s['patient'])(urldecode($m[1])));
            }

            if (str_ends_with($path, '/patients') && $method === 'GET') {
                parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);

                return Http::response(['data' => ($s['search'])($q['where'] ?? '')], 200);
            }
        }

        $s['unexpected'][] = "{$method} {$url}";

        return Http::response(['unexpected' => $url], 599);
    };
}

/** Respuesta de createEvent tal como la devuelve SMD: {message, payload, physician, _id}. */
function auditCreateEventResponse(string $eventId): array
{
    return [
        'message'   => 'event_created_successfully',
        'payload'   => ['eventId' => (int) ('17'.rand(10000000, 99999999)), 'summary' => 'CONSULTA: test'],
        'physician' => ['_id' => 'smd-doc', 'name' => 'Audit'],
        '_id'       => $eventId,
    ];
}

function auditDoctor(array $attrs = []): int
{
    return DB::table('doctors')->insertGetId(array_merge([
        'name'       => 'Dr Audit '.uniqid(),
        'email'      => 'audit.'.uniqid().'@odontoking.test',
        'unique_id'  => 'smd-doc-'.uniqid(),
        'is_active'  => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

function auditShift(int $doctorId, Carbon $day, string $start = '08:00', string $end = '18:00'): void
{
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $day->toDateString(),
        'start_time' => $start,
        'end_time'   => $end,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function auditPerson(array $attrs = []): Person
{
    return Person::create(array_merge([
        'name'            => 'Paciente Audit '.uniqid(),
        'contact_numbers' => [['value' => '7'.rand(1000000, 9999999), 'label' => 'work']],
        'entity_type'     => 'persons',
    ], $attrs));
}

/**
 * Cita local (meeting + lead) vinculada opcionalmente a un evento de SMD.
 */
function auditAppointment(int $doctorId, int $personId, Carbon $from, ?string $externalId = null, ?int $stageId = null): array
{
    $activityId = DB::table('activities')->insertGetId([
        'type'          => 'meeting',
        'title'         => 'Cita Audit '.uniqid(),
        'comment'       => 'motivo',
        'schedule_from' => $from->format('Y-m-d H:i:s'),
        'schedule_to'   => $from->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
        'user_id'       => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('doctor_activities')->insert(['doctor_id' => $doctorId, 'activity_id' => $activityId]);
    DB::table('activity_participants')->insert(['person_id' => $personId, 'activity_id' => $activityId]);

    $lead = Lead::create(['title' => 'Lead Audit', 'description' => 't', 'entity_type' => 'leads']);
    DB::table('leads')->where('id', $lead->id)->update([
        'person_id'              => $personId,
        'lead_pipeline_stage_id' => $stageId ?? (int) config('smd.stage_map.confirmed'),
    ]);
    DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

    if ($externalId) {
        DB::table('smd_synced_events')->insert([
            'external_id' => $externalId,
            'activity_id' => $activityId,
            'lead_id'     => $lead->id,
            'raw_payload' => json_encode(['_id' => $externalId, 'eventId' => 'evt-1']),
            'status'      => 'CONFIRMED',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    return ['activity_id' => $activityId, 'lead_id' => $lead->id];
}

/** Todos los requests salientes a SMD con el path dado. */
function auditSent(string $pathFragment): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), $pathFragment))
        ->map(fn ($pair) => $pair[0])
        ->values()
        ->all();
}

/**
 * Configura SMD/Dropbox apuntando a hosts de prueba, bloquea cualquier request
 * real y registra el router. Devuelve el estado mutable que lee el router.
 */
function auditBootFakes(): ArrayObject
{
    config([
        'services.sharemedata.api_key'        => 'test-key',
        'services.sharemedata.base_url'       => 'https://smd.test/api/calendar',
        'services.sharemedata.patients_url'   => 'https://smd.test/api',
        'services.sharemedata.webhook_secret' => null,
        'smd.validate_availability'           => true,
        'smd.dropbox.app_secret'              => 'dropbox-secret',
        'smd.dropbox.app_key'                 => 'dropbox-key',
        'smd.dropbox.refresh_token'           => 'dropbox-refresh',
        'smd.dropbox.appointments_folder'     => '/smd-events',
    ]);

    $state = new ArrayObject([
        'specialties'     => ['General', 'Ortodoncia'],
        'availability'    => [],
        // Forma REAL de SMD (logs de producción): el _id del evento va en la RAÍZ.
        'createEvent'     => auditCreateEventResponse('evt-'.uniqid()),
        'updateEvent'     => ['message' => 'ok'],
        'createPatient'   => ['_id' => 'smd-pat-'.uniqid()],
        'patient'         => fn (string $id) => Http::response(['message' => '@error.usercantfound'], 404),
        'search'          => fn (string $where) => [],
        'dropbox_changes' => ['entries' => [], 'cursor' => 'cursor-2', 'has_more' => false],
        'dropbox_folders' => [],
        'dropbox_files'   => [],
        'unexpected'      => [],
    ]);

    // Http::fake() acumula stubs y el Factory es singleton: se descarta antes.
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();
    Http::preventStrayRequests();
    Http::fake(auditRouter($state));

    \Illuminate\Support\Facades\Cache::forget('dropbox_access_token');

    return $state;
}
