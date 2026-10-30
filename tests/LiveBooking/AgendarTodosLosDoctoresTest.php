<?php

/*
|--------------------------------------------------------------------------
| Agenda una cita de prueba con CADA doctor disponible (entorno de pruebas)
|--------------------------------------------------------------------------
|
| Usa el mismo camino que el Flow de WhatsApp y el agente
| (AppointmentService::process) con la validación de SMD ENCENDIDA solo acá:
| disponibilidad en SMD → createEvent → PATCH con paciente → Lead + Activity
| en el CRM vinculados al evento.
|
| Las citas QUEDAN creadas, en SMD y en el CRM ("PRUEBA CRM - NO ATENDER"),
| para verlas en la agenda de cada doctor. Por eso esta carpeta no usa
| DatabaseTransactions y se niega a correr contra SMD o la BD de producción.
|
|     php artisan integraciones:check --agendar
|
| Paciente: SMD_LIVE_PERSON_ID (id de persons) si está definido; si no, el
| último paciente con SMD_LIVE_PATIENT_PHONE. En los dos casos su
| smd_patient_id tiene que existir en SMD gamma.
*/

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\AppointmentService;
use Webkul\Admin\Services\ShareMeDataService;

beforeEach(function () {
    if (getenv('AGENDAR_TODOS_TESTS') !== '1') {
        $this->markTestSkipped('Escribe en SMD y en la BD: usar `php artisan integraciones:check --agendar`.');
    }

    // Nunca contra producción: estas citas no se borran.
    expect(DB::connection()->getDatabaseName())->not->toBe(getenv('ENTORNO_PROD_DB') ?: 'odontoking', 'La BD activa es la de PRODUCCIÓN: no se agenda nada');
    expect((string) config('services.sharemedata.base_url'))->not->toContain(getenv('ENTORNO_PROD_SMD_HOST') ?: 'app.sharemedata.com', 'SMD apunta a PRODUCCIÓN: no se agenda nada');

    Http::allowStrayRequests();

    // El .env de pruebas la tiene apagada; sin SMD la cita sería solo local.
    config(['smd.validate_availability' => true]);

    $this->tz = config('app.timezone', 'America/La_Paz');
    $this->smd = app(ShareMeDataService::class);
});

/** Paciente de prueba del CRM que también existe en SMD. */
function bookingPatient(ShareMeDataService $smd): object
{
    if ($personId = getenv('SMD_LIVE_PERSON_ID')) {
        $person = DB::table('persons')->where('id', (int) $personId)->first(['id', 'name', 'smd_patient_id']);

        expect($person)->not->toBeNull("SMD_LIVE_PERSON_ID={$personId} no existe en esta BD");
        expect($person->smd_patient_id && $smd->getPatient($person->smd_patient_id))
            ->toBeTrue("{$person->name} tiene smd_patient_id {$person->smd_patient_id}, que no existe en SMD (¿es el id de producción?)");

        return $person;
    }

    $phone = preg_replace('/\D/', '', (string) getenv('SMD_LIVE_PATIENT_PHONE'));
    expect($phone)->not->toBeEmpty('Definir SMD_LIVE_PATIENT_PHONE en .env');

    $candidates = DB::table('persons')
        ->where('contact_numbers', 'like', '%'.substr($phone, -8).'%')
        ->whereNotNull('smd_patient_id')
        ->orderByDesc('id')
        ->get(['id', 'name', 'smd_patient_id']);

    foreach ($candidates as $person) {
        if ($smd->getPatient($person->smd_patient_id)) {
            return $person;
        }
    }

    test()->fail("Ningún paciente del CRM con teléfono {$phone} existe en SMD (".$candidates->pluck('name')->implode(', ').')');
}

/** Médicos con agenda en SMD los próximos 14 días: [_id => [especialidades]]. */
function bookingPhysicians(ShareMeDataService $smd, Carbon $from, Carbon $to): array
{
    $physicians = [];

    foreach (array_filter((array) $smd->getSpecialties(), 'is_string') as $specialty) {
        $rows = $smd->availabilityBySpecialty($specialty, (string) config('smd.default_subsidiary', 'Santa Cruz'), $from->toIso8601String(), $to->toIso8601String());

        foreach ((array) $rows as $row) {
            if (! empty($row['physician']['_id']) && empty($row['physician']['archived'])) {
                $physicians[$row['physician']['_id']][] = $specialty;
            }
        }
    }

    return $physicians;
}

/**
 * Primer bloque libre de 15 min en SMD que cae dentro de un turno del doctor
 * en el CRM (AppointmentService exige las dos cosas).
 */
function bookingSlot(ShareMeDataService $smd, object $doctor, array $specialties, Carbon $from, Carbon $to): ?array
{
    $shifts = DB::table('doctor_shifts')
        ->where('doctor_id', $doctor->id)
        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
        ->get(['date', 'start_time', 'end_time']);

    foreach ($specialties as $specialty) {
        $days = $smd->checkAvailability($doctor->unique_id, $specialty, (string) config('smd.default_subsidiary', 'Santa Cruz'), $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'));

        foreach (collect($days)->flatMap(fn ($day) => collect($day)->flatten(1)) as $interval) {
            if (empty($interval['start']) || empty($interval['end'])) {
                continue;
            }

            $start = Carbon::parse($interval['start'])->setTimezone(config('app.timezone'));
            $end = Carbon::parse($interval['end'])->setTimezone(config('app.timezone'));

            $inShift = $shifts->contains(fn ($s) => (string) $s->date === $start->toDateString()
                && substr($s->start_time, 0, 5) <= $start->format('H:i')
                && substr($s->end_time, 0, 5) >= $end->format('H:i'));

            if ($inShift) {
                return ['start' => $start, 'end' => $end, 'specialty' => $specialty];
            }
        }
    }

    return null;
}

it('agenda una cita con cada doctor que tiene agenda en SMD y queda en el CRM vinculada al evento', function () {
    $from = now($this->tz)->addDay()->startOfDay();
    $to = $from->copy()->addDays(13)->endOfDay();

    $patient = bookingPatient($this->smd);
    $physicians = bookingPhysicians($this->smd, $from, $to);

    expect($physicians)->not->toBeEmpty('SMD no devolvió ningún médico con agenda en 14 días');

    $ok = [];
    $fallas = [];

    foreach ($physicians as $smdId => $specialties) {
        $doctor = DB::table('doctors')->where('unique_id', $smdId)->orderByDesc('is_active')->orderBy('id')->first();
        $label = $doctor ? trim(str_replace("\n", '', $doctor->name))." (#{$doctor->id})" : "SMD {$smdId}";

        if (! $doctor || ! $doctor->is_active) {
            $fallas[] = "{$label}: sin doctor ACTIVO en el CRM con ese id de SMD";

            continue;
        }

        // Las citas quedan: volver a correrlo solo completa a los que faltan
        // (por doctor Y paciente: otro paciente de prueba no cuenta).
        $existing = DB::table('activities as a')
            ->join('doctor_activities as da', 'da.activity_id', '=', 'a.id')
            ->join('activity_participants as pa', 'pa.activity_id', '=', 'a.id')
            ->where('da.doctor_id', $doctor->id)
            ->where('pa.person_id', $patient->id)
            ->where('a.title', 'like', '%PRUEBA CRM - NO ATENDER%')
            ->where('a.schedule_from', '>=', now($this->tz)->format('Y-m-d H:i:s'))
            ->value('a.id');

        if ($existing) {
            $ok[] = "{$label}: ya tenía la cita de prueba {$existing}";

            continue;
        }

        $slot = bookingSlot($this->smd, $doctor, array_unique($specialties), $from, $to);

        if (! $slot) {
            $fallas[] = "{$label}: ningún horario libre de SMD cae dentro de sus turnos del CRM (doctor_shifts) en 14 días";

            continue;
        }

        try {
            $result = app(AppointmentService::class)->process([
                'doctor_id'     => $doctor->id,
                'doctor_ids'    => [$doctor->id],
                // Como el Flow: la especialidad con la que se eligió el horario.
                'specialty_id'  => DB::table('specialties')->where('name', $slot['specialty'])->value('id'),
                'person'        => ['id' => $patient->id],
                'schedule_from' => $slot['start']->format('Y-m-d H:i:s'),
                'schedule_to'   => $slot['end']->format('Y-m-d H:i:s'),
                'title'         => "PRUEBA CRM - NO ATENDER - {$slot['specialty']}",
                'reason'        => 'Cita de prueba automática (integraciones:check --agendar).',
            ]);
        } catch (\Throwable $e) {
            $details = method_exists($e, 'getDetails') ? $e->getDetails() : [];
            $fallas[] = "{$label}: ".$e->getMessage()
                ." [horario {$slot['start']->format('d/m H:i')} {$slot['specialty']}"
                .($details ? ' · '.json_encode($details['smd_errors'] ?? $details, JSON_UNESCAPED_UNICODE) : '').']';

            continue;
        }

        // La cita local tiene que quedar con ESTE doctor y vinculada al evento de SMD.
        $doctorIds = DB::table('doctor_activities')->where('activity_id', $result['activity_id'])->pluck('doctor_id')->all();
        $smdEvent = DB::table('smd_synced_events')->where('activity_id', $result['activity_id'])->value('external_id');

        if (! in_array($doctor->id, $doctorIds, true) || ! $smdEvent) {
            $fallas[] = "{$label}: cita {$result['activity_id']} creada pero ".(! $smdEvent ? 'sin evento de SMD vinculado' : 'con otro doctor ('.implode(',', $doctorIds).')');

            continue;
        }

        $ok[] = sprintf('%s: %s %s (%s) → activity %d, lead %d, SMD %s',
            $label, $slot['start']->format('d/m H:i'), $slot['specialty'], $patient->name, $result['activity_id'], $result['lead_id'], $smdEvent);
    }

    fwrite(STDOUT, "\nCitas creadas (".count($ok)."):\n  ".implode("\n  ", $ok ?: ['ninguna'])."\n");

    expect($fallas)->toBeEmpty('Doctores sin cita ('.count($fallas)."):\n".implode("\n", $fallas));
});
