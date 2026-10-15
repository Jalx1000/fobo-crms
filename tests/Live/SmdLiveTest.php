<?php

/*
|--------------------------------------------------------------------------
| Pruebas LIVE contra ShareMeData y Dropbox de PRODUCCIÓN
|--------------------------------------------------------------------------
|
| NO forman parte de la suite normal (tests/Live no está en phpunit.xml).
| Se corren solo con el comando, pensado para los domingos:
|
|     php artisan smd:test-live            # solo lectura
|     php artisan smd:test-live --write    # + paciente y cita de prueba en SMD
|
| Usan el .env de producción como toda la suite. El comando pone
| SMD_LIVE_TESTS=1: sin esa variable todo se saltea y nunca corren por accidente.
|
| Escritura (SMD_LIVE_WRITE=1) necesita en .env:
|   SMD_LIVE_DOCTOR_ID       id del doctor DE PRUEBA en el CRM (con unique_id de SMD)
|   SMD_LIVE_PATIENT_PHONE   teléfono del paciente DE PRUEBA
|   SMD_LIVE_SPECIALTY       (opcional) especialidad con la que consultar huecos
| El paciente de prueba debe EXISTIR en SMD (creado a mano): la API exige
| birthday/gender/personID para el alta. Si tiene cédula, se manda en personID. La cita se crea con el resumen
| "PRUEBA CRM - NO ATENDER" y se cancela al final, aunque falle una verificación.
*/

use Carbon\Carbon;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\DropboxService;
use Webkul\Admin\Services\ShareMeDataService;

beforeEach(function () {
    if (getenv('SMD_LIVE_TESTS') !== '1') {
        $this->markTestSkipped('Pruebas live desactivadas: usar `php artisan smd:test-live`.');
    }

    // TestCase bloquea todo request saliente; estas pruebas existen para salir.
    // Ojo: Http::preventStrayRequests(false) NO sirve, la facade ignora el
    // argumento y vuelve a activar el bloqueo.
    Http::allowStrayRequests();

    // Los servicios se tragan los errores de red y devuelven valores vacíos que
    // parecen válidos: se registran las respuestas reales para exigirlas.
    $this->responses = new ArrayObject;
    $responses = $this->responses;
    Event::listen(ResponseReceived::class, function (ResponseReceived $e) use ($responses) {
        $responses[] = [$e->request->url(), $e->response->status()];
    });

    $this->tz = config('app.timezone', 'America/La_Paz');
    $this->smd = app(ShareMeDataService::class);
    $this->dropbox = app(DropboxService::class);
});

/** Doctor para las consultas: el de prueba si está definido, si no cualquiera vinculado. */
function liveDoctor(): object
{
    $id = getenv('SMD_LIVE_DOCTOR_ID');

    $doctor = $id
        ? DB::table('doctors')->where('id', (int) $id)->first()
        : DB::table('doctors')->where('is_active', 1)->whereNotNull('unique_id')->where('unique_id', '!=', '')->orderBy('id')->first();

    expect($doctor)->not->toBeNull('No hay doctor vinculado a SMD para probar')
        ->and($doctor->unique_id)->not->toBeEmpty('El doctor de prueba no tiene unique_id de SMD');

    return $doctor;
}

/** Exige que haya habido al menos una respuesta HTTP 2xx real hacia esa URL. */
function liveAssertAnswered(ArrayObject $responses, string $urlFragment): void
{
    $ok = collect($responses)->contains(fn ($r) => str_contains($r[0], $urlFragment) && $r[1] >= 200 && $r[1] < 300);

    expect($ok)->toBeTrue("No hubo respuesta 2xx real de {$urlFragment}: ¿red bloqueada o credenciales?");
}

/** Carpetas de Dropbox de los últimos $days días (la fecha es la de escritura). */
function liveRecentFiles(DropboxService $dropbox, int $days = 7): array
{
    for ($i = 0; $i < $days; $i++) {
        $files = $dropbox->listFilesForDate(now()->subDays($i)->toDateString());

        if ($files) {
            return $files;
        }
    }

    return [];
}

/*
|--------------------------------------------------------------------------
| Dropbox (SMD → CRM)
|--------------------------------------------------------------------------
*/

describe('Dropbox', function () {
    it('renueva el token y lista archivos recientes de la carpeta de SMD', function () {
        $files = liveRecentFiles($this->dropbox);

        expect($files)->not->toBeEmpty('Sin archivos en los últimos 7 días: ¿SMD dejó de escribir o cambió la carpeta?');
        expect($files[0])->toHaveKeys(['name', 'path_lower', 'content_hash']);
    });

    it('baja un evento y tiene la forma que espera el sync', function () {
        $files = liveRecentFiles($this->dropbox);
        $json = $this->dropbox->downloadJson(end($files)['path_lower']);

        expect($json)->toBeArray()
            ->toHaveKeys(['_id', 'startDate', 'endDate', 'attendances', 'updated_at', 'status']);
        expect($json['startDate'])->toEndWith('Z');
    });

    it('el cursor del webhook sigue siendo válido y apunta a la carpeta configurada', function () {
        $cursor = DB::table('settings')->where('key', 'dropbox_cursor')->value('value');
        expect($cursor)->not->toBeEmpty('No hay cursor: correr `php artisan smd:dropbox-init-cursor`');

        // Solo lectura: no se guarda el cursor nuevo.
        $result = $this->dropbox->listChanges($cursor);
        liveAssertAnswered($this->responses, 'list_folder/continue');
        expect($result['reset'] ?? false)->toBeFalse('Dropbox reseteó el cursor: re-inicializarlo');

        foreach ($result['entries'] as $entry) {
            expect($entry['path_lower'])->toStartWith(strtolower(rtrim(config('smd.dropbox.appointments_folder'), '/')));
        }
    });

    it('el último archivo de SMD ya está sincronizado en el CRM', function () {
        $files = liveRecentFiles($this->dropbox, 2);

        if (! $files) {
            $this->markTestSkipped('Sin archivos en las últimas 48 h.');
        }

        // El más viejo de la carpeta más reciente: le dio tiempo al webhook/cron.
        usort($files, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $json = $this->dropbox->downloadJson($files[0]['path_lower']);

        expect(DB::table('smd_synced_events')->where('external_id', $json['_id'])->exists())
            ->toBeTrue("El evento {$json['_id']} ({$files[0]['name']}) no está en smd_synced_events");
    });
});

/*
|--------------------------------------------------------------------------
| Webhooks públicos del CRM (APP_URL real)
|--------------------------------------------------------------------------
*/

describe('webhooks públicos', function () {
    it('el handshake de Dropbox responde el challenge', function () {
        $res = Http::timeout(15)->get(rtrim(config('app.url'), '/').'/api/webhooks/dropbox', ['challenge' => 'live-check']);

        expect($res->status())->toBe(200)->and($res->body())->toBe('live-check');
    });

    it('el webhook de Dropbox rechaza un POST sin firma', function () {
        $res = Http::timeout(15)->post(rtrim(config('app.url'), '/').'/api/webhooks/dropbox', []);

        expect($res->status())->toBe(403);
    });

    it('el webhook de SMD exige autenticación (body vacío: nunca crea nada)', function () {
        $res = Http::timeout(15)->acceptJson()->post(rtrim(config('app.url'), '/').'/api/webhooks/sharemedata', []);

        // 422 significa que pasó sin credenciales y llegó a validar: está abierto (AUD-01).
        expect($res->status())->toBe(401);
    });
});

/*
|--------------------------------------------------------------------------
| API de ShareMeData (CRM → SMD), lectura
|--------------------------------------------------------------------------
*/

describe('ShareMeData lectura', function () {
    it('responde el catálogo de especialidades', function () {
        $specialties = $this->smd->getSpecialties();

        expect($this->smd->getLastResponse()['status'] ?? null)->toBe(200)
            ->and($specialties)->not->toBeEmpty();
    });

    it('consulta la disponibilidad del doctor para los próximos 7 días', function () {
        $doctor = liveDoctor();
        $from = now($this->tz)->addDay()->startOfDay();

        $this->smd->checkAvailability(
            $doctor->unique_id, 'General', config('smd.default_subsidiary', 'Santa Cruz'),
            $from->format('Y-m-d H:i:s'), $from->copy()->addDays(6)->endOfDay()->format('Y-m-d H:i:s'),
        );

        expect($this->smd->getLastResponse()['status'] ?? null)->toBe(200);
    });

    it('lee la ficha de un paciente ya vinculado', function () {
        $smdId = DB::table('persons')->whereNotNull('smd_patient_id')->orderByDesc('id')->value('smd_patient_id');

        expect($this->smd->getPatient($smdId))->toBeArray()->toHaveKey('_id');
    });

    it('busca pacientes por teléfono sin error', function () {
        $phone = getenv('SMD_LIVE_PATIENT_PHONE') ?: preg_replace('/\D/', '', (string) json_decode(
            DB::table('persons')->whereNotNull('smd_patient_id')->orderByDesc('id')->value('contact_numbers') ?? '[]', true
        )[0]['value'] ?? '');

        expect($phone)->not->toBeEmpty();
        expect($this->smd->searchPatient(substr($phone, -8)))->toBeArray();
        liveAssertAnswered($this->responses, '/patients');
    });
});

/*
|--------------------------------------------------------------------------
| API de ShareMeData, ESCRITURA (solo con --write)
|--------------------------------------------------------------------------
*/

describe('ShareMeData escritura', function () {
    beforeEach(function () {
        if (getenv('SMD_LIVE_WRITE') !== '1') {
            $this->markTestSkipped('Escritura desactivada: usar `php artisan smd:test-live --write`.');
        }

        if (! getenv('SMD_LIVE_DOCTOR_ID') || ! getenv('SMD_LIVE_PATIENT_PHONE')) {
            $this->fail('Para --write hay que definir SMD_LIVE_DOCTOR_ID y SMD_LIVE_PATIENT_PHONE en .env');
        }
    });

    it('flujo completo: paciente existente → hueco libre → createEvent → PATCH attendances → Dropbox con paciente → cancelar', function () {
        $doctor = liveDoctor();
        $phone = preg_replace('/\D/', '', getenv('SMD_LIVE_PATIENT_PHONE'));

        // 1. Paciente de prueba: debe EXISTIR en SMD, creado a mano. El alta por
        // API exige birthday, gender y personID (cédula), que el CRM no manda
        // (AUD-12): crear pacientes desde acá deja basura o falla.
        $patient = collect($this->smd->searchPatient($phone))->first();
        expect($patient)->not->toBeNull("No existe en SMD un paciente con teléfono {$phone}: crearlo a mano en SMD (con cédula) y volver a correr");

        $patientId = $patient['_id'];
        $patientCi = (string) ($patient['personID'] ?? '');

        // 2. Primer bloque libre de 15 min en los próximos 14 días. SMD filtra la
        // disponibilidad por especialidad, así que se prueba con las del doctor
        // (igual que AvailabilityResolverService) y, si no tiene, con todo el
        // catálogo de SMD. SMD_LIVE_SPECIALTY fuerza una sola.
        $from = now($this->tz)->addDay()->startOfDay();
        $to = $from->copy()->addDays(13)->endOfDay();

        $specialties = getenv('SMD_LIVE_SPECIALTY')
            ? [getenv('SMD_LIVE_SPECIALTY')]
            : DB::table('doctor_specialty')
                ->join('specialties', 'specialties.id', '=', 'doctor_specialty.specialty_id')
                ->where('doctor_specialty.doctor_id', $doctor->id)
                ->pluck('specialties.name')->filter()->values()->all();

        if (! $specialties) {
            $specialties = array_values(array_filter((array) $this->smd->getSpecialties(), 'is_string'));
        }

        $slot = null;
        $tried = [];

        foreach ($specialties as $specialty) {
            $slots = $this->smd->checkAvailability(
                $doctor->unique_id, $specialty, config('smd.default_subsidiary', 'Santa Cruz'),
                $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'),
            );

            $body = $this->smd->getLastResponse()['body'] ?? [];
            $physicians = collect(is_array($body) ? $body : [])->pluck('physician._id')->filter();
            $tried[] = sprintf('%s (HTTP %s, %d médicos, doctor %s)', $specialty,
                $this->smd->getLastResponse()['status'] ?? '?', $physicians->count(),
                $physicians->contains($doctor->unique_id) ? 'presente' : 'AUSENTE');

            $slot = collect($slots)->flatMap(fn ($day) => collect($day)->flatten(1))->first(fn ($iv) => ! empty($iv['start']));

            if ($slot) {
                break;
            }
        }

        expect($slot)->not->toBeNull(sprintf(
            'Sin huecos libres en 14 días para el doctor %d (%s, unique_id %s). Probado: %s',
            $doctor->id, $doctor->name, $doctor->unique_id, implode('; ', $tried) ?: 'ninguna especialidad'
        ));

        $start = Carbon::parse($slot['start'])->setTimezone($this->tz);
        $end = Carbon::parse($slot['end'])->setTimezone($this->tz);

        // 3. Crear el evento. En SMD `personID` es la CÉDULA, no el _id (AUD-11).
        $result = $this->smd->createEvent([
            'summary'   => 'PRUEBA CRM - NO ATENDER',
            'physician' => ['_id' => $doctor->unique_id, 'email' => $doctor->email ?? ''],
            'patient'   => ['name' => $patient['name'] ?? 'PRUEBA', 'lastName' => $patient['lastName'] ?? 'CRM', 'phone' => $phone, 'personID' => $patientCi, 'birthday' => ''],
            'slot'      => ['start' => $start->format('Y-m-d\TH:i:sP'), 'end' => $end->format('Y-m-d\TH:i:sP')],
        ]);

        expect($result['success'])->toBeTrue('createEvent falló: '.json_encode($result['message'] ?? null));

        // SMD responde {message, payload:{eventId,…}, physician, _id}: el _id del
        // evento viene en la RAÍZ, no dentro de payload.
        $data = (array) ($result['data'] ?? []);
        $eventId = $data['_id'] ?? ($data['payload']['_id'] ?? null);
        $eventNumber = $data['payload']['eventId'] ?? ($data['eventId'] ?? null);

        // A partir de acá la cita EXISTE en SMD: hay que cancelarla pase lo que pase.
        $cancel = ['success' => false, 'message' => 'no se intentó'];
        $failure = null;

        try {
            expect($eventId)->not->toBeEmpty('createEvent respondió OK pero sin _id. Respuesta: '.json_encode($data, JSON_UNESCAPED_UNICODE));

            // 4. Igual que el CRM (AppointmentService::syncEventAttendances):
            // createEvent no asocia al paciente, así que se completa por PATCH.
            $patch = $this->smd->updateEvent($eventId, [
                '_id'         => $eventId,
                'eventId'     => $eventNumber,
                'type'        => 'event',
                'summary'     => 'PRUEBA CRM - NO ATENDER',
                'description' => 'Cita de prueba automática (smd:test-live).',
                'startDate'   => $start->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'endDate'     => $end->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'attendances' => [
                    ['_id' => $patientId, 'name' => $patient['name'] ?? '', 'lastName' => $patient['lastName'] ?? '', 'type' => 'patient', 'phone' => $phone],
                    ['_id' => $doctor->unique_id, 'name' => $doctor->name, 'lastName' => '', 'type' => 'physician', 'phone' => ''],
                ],
                'status'      => (string) config('smd.smd_status.confirmed', 'CONFIRMED'),
            ]);
            expect($patch['success'])->toBeTrue('El PATCH de attendances falló: '.json_encode($patch['message'] ?? null));

            // 5. SMD publica en Dropbox una versión CON el paciente (cierra el
            // círculo SMD → CRM: sin paciente, el sync la toma como bloqueo).
            $wait = (int) (getenv('SMD_LIVE_DROPBOX_WAIT') ?: 120);
            $published = null;
            $lastSeen = null;

            for ($t = 0; $t <= $wait && ! $published; $t += 10) {
                foreach (array_unique([now()->toDateString(), now($this->tz)->toDateString()]) as $date) {
                    $files = collect($this->dropbox->listFilesForDate($date))
                        ->filter(fn ($f) => str_contains($f['name'], $eventId))
                        ->sortBy('name');

                    foreach ($files as $file) {
                        $json = $this->dropbox->downloadJson($file['path_lower']);
                        $lastSeen = $json;

                        if (in_array($patientId, collect($json['attendances'] ?? [])->pluck('_id')->all(), true)) {
                            $published = $json;
                            break 2;
                        }
                    }
                }

                if (! $published && $t < $wait) {
                    sleep(10);
                }
            }

            expect($published)->not->toBeNull($lastSeen
                ? "El evento {$eventId} llegó a Dropbox pero SIN el paciente (AUD-11). attendances: ".json_encode($lastSeen['attendances'] ?? [], JSON_UNESCAPED_UNICODE)
                : "El evento {$eventId} no apareció en Dropbox en {$wait}s");
        } catch (\Throwable $e) {
            // Se guarda para re-lanzarlo DESPUÉS de cancelar y verificar la
            // cancelación: con finally, una falla acá ocultaba si se canceló.
            $failure = $e;
        }

        // 6. Cancelar SIEMPRE que haya _id, aunque falle cualquier verificación.
        if ($eventId) {
            $cancel = $this->smd->updateEvent($eventId, [
                '_id'         => $eventId,
                'eventId'     => $eventNumber,
                'type'        => 'event',
                'summary'     => 'PRUEBA CRM - NO ATENDER',
                'description' => 'Cita de prueba automática (smd:test-live), cancelada.',
                'startDate'   => $start->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'endDate'     => $end->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'attendances' => [
                    ['_id' => $patientId, 'name' => $patient['name'] ?? '', 'lastName' => $patient['lastName'] ?? '', 'type' => 'patient', 'phone' => $phone],
                    ['_id' => $doctor->unique_id, 'name' => $doctor->name, 'lastName' => '', 'type' => 'physician', 'phone' => ''],
                ],
                'status'      => (string) config('smd.smd_status.cancelled', 'CANCELED'),
            ]);
        }

        if ($eventId) {
            expect($cancel['success'])->toBeTrue("No se pudo cancelar el evento de prueba {$eventId}: CANCELARLO A MANO EN SMD (".json_encode($cancel['message'] ?? null).')');
        }

        if ($failure) {
            throw $failure;
        }
    });
});
