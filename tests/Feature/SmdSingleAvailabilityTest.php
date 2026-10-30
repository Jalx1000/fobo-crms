<?php

/*
|--------------------------------------------------------------------------
| Modo `single`: una consulta a SMD con "General" para todo
|--------------------------------------------------------------------------
| En SMD todos los doctores están en "General"; las especialidades viven en
| Sofo (planning/16-especialidades-en-crm). Antes, validar una cita de un
| doctor con 3 especialidades (Adriana Soria) hacía 3 consultas a SMD.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Exceptions\AppointmentException;
use Webkul\Admin\Services\AppointmentService;
use Webkul\Admin\Services\AvailabilityResolverService;

function singleSpecialty(string $name): int
{
    return DB::table('specialties')->where('name', $name)->value('id')
        ?? DB::table('specialties')->insertGetId([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
}

function singleBook(int $doctorId, int $personId, Carbon $from, int $minutes = 60): array
{
    return app(AppointmentService::class)->process([
        'doctor_id'     => $doctorId,
        'person'        => ['id' => $personId],
        'schedule_from' => $from->format('Y-m-d H:i:s'),
        'schedule_to'   => $from->copy()->addMinutes($minutes)->format('Y-m-d H:i:s'),
        'reason'        => 'Test modo single',
    ]);
}

beforeEach(function () {
    $this->smd = auditBootFakes();
    config([
        'smd.availability_mode'      => 'single',
        'smd.availability_specialty' => 'General',
    ]);

    // Como Adriana Soria: tres especialidades en Sofo.
    $this->doctorId = auditDoctor(['name' => 'Dra Tres Especialidades '.uniqid()]);
    foreach (['Ortodoncia', 'Ortopedia', 'General'] as $name) {
        DB::table('doctor_specialty')->insert(['doctor_id' => $this->doctorId, 'specialty_id' => singleSpecialty($name)]);
    }

    $this->uid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
    $this->day = Carbon::tomorrow(config('app.timezone'));
    auditShift($this->doctorId, $this->day, '08:00', '12:00');
    $this->person = auditPerson();

    foreach ([1, 7] as $days) {
        Cache::forget('smd:agenda:'.$this->day->toDateString().':'.$days);
    }
});

afterEach(function () {
    foreach ([1, 7] as $days) {
        Cache::forget('smd:agenda:'.$this->day->toDateString().':'.$days);
    }
});

it('validar la cita de un doctor con 3 especialidades hace UNA consulta, con General', function () {
    $this->smd['availability'] = auditSmdAvailability($this->uid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(12, 0));

    $result = singleBook($this->doctorId, $this->person->id, $this->day->copy()->setTime(10, 0));
    $consultas = auditSent('/schedule/availability');

    expect($result['activity_id'])->not->toBeNull()
        ->and($consultas)->toHaveCount(1)
        ->and(urldecode($consultas[0]->url()))->toContain('specialty=General');
});

it('rechaza la cita si un bloque de 15 min del horario está ocupado', function () {
    // Libre 10:00–10:45: la cita de 10:00 a 11:00 no entra.
    $this->smd['availability'] = auditSmdAvailability($this->uid, $this->day->copy()->setTime(10, 0), $this->day->copy()->setTime(10, 45));

    expect(fn () => singleBook($this->doctorId, $this->person->id, $this->day->copy()->setTime(10, 0)))
        ->toThrow(AppointmentException::class, 'no tiene disponibilidad');
    expect(auditSent('/schedule/availability'))->toHaveCount(1);
});

it('si SMD no responde, lo dice: no es lo mismo que "sin disponibilidad"', function () {
    $this->smd['availability'] = Http::response(['error' => 'x'], 503);

    expect(fn () => singleBook($this->doctorId, $this->person->id, $this->day->copy()->setTime(10, 0)))
        ->toThrow(AppointmentException::class, 'no respondió');
});

it('en modo per_specialty sigue validando como antes', function () {
    config(['smd.availability_mode' => 'per_specialty']);
    $this->smd['availability'] = auditSmdAvailability($this->uid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(12, 0));

    $result = singleBook($this->doctorId, $this->person->id, $this->day->copy()->setTime(10, 0));

    expect($result['activity_id'])->not->toBeNull()
        ->and(urldecode(auditSent('/schedule/availability')[0]->url()))->not->toContain('specialty=General');
});

it('el alta de lead con cita (LeadController) valida igual: 1 consulta y todos los bloques libres', function () {
    // Libre 10:00–10:45: la cita de 10:00 a 11:00 no entra. La validación vieja
    // del controlador la daba por buena porque SMD devolvía "algún" bloque.
    $this->smd['availability'] = auditSmdAvailability($this->uid, $this->day->copy()->setTime(10, 0), $this->day->copy()->setTime(10, 45));
    $from = $this->day->copy()->setTime(10, 0);

    \Pest\Laravel\actingAs(\Webkul\User\Models\User::find(1), 'user')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->post(route('admin.leads.store'), [
            'title'             => 'Lead modo single '.uniqid(),
            'person'            => ['id' => $this->person->id, 'name' => $this->person->name],
            'doctor_id'         => $this->doctorId,
            'appointment_start' => $from->format('Y-m-d H:i:s'),
            'appointment_end'   => $from->copy()->addHour()->format('Y-m-d H:i:s'),
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'El doctor no tiene disponibilidad en SHAREMEDATA para el horario solicitado.']);

    $consultas = auditSent('/schedule/availability');
    expect($consultas)->toHaveCount(1)
        ->and(urldecode($consultas[0]->url()))->toContain('specialty=General');
});

/*
| T-4: la disponibilidad que se muestra (resolver) usa la agenda compartida.
*/

it('el resolver pide la agenda UNA vez con General y la reusa para el mismo rango', function () {
    $this->smd['availability'] = auditSmdAvailability($this->uid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(11, 0));
    $resolver = app(AvailabilityResolverService::class);

    $first = $resolver->resolve($this->doctorId, $this->day->copy(), 1, ['duration_minutes' => 60]);
    $resolver->resolve($this->doctorId, $this->day->copy(), 1, ['duration_minutes' => 60]);
    $consultas = auditSent('/schedule/availability');

    expect($first['source'])->toBe('smd')
        ->and($first['schedule'][0]['slots'])->not->toBeEmpty()
        ->and($consultas)->toHaveCount(1)
        ->and(urldecode($consultas[0]->url()))->toContain('specialty=General');
});

it('el resolver degrada a local con smd_unavailable si SMD da 5xx', function () {
    $this->smd['availability'] = Http::response(['error' => 'x'], 503);

    $result = app(AvailabilityResolverService::class)->resolve($this->doctorId, $this->day->copy(), 1, ['duration_minutes' => 60]);

    expect($result)->toMatchArray(['source' => 'local', 'degraded' => true, 'reason' => 'smd_unavailable']);
});

it('un doctor que SMD no publica sale sin horarios, no degradado', function () {
    $this->smd['availability'] = auditSmdAvailability('otro-medico-'.uniqid(), $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(11, 0));

    $result = app(AvailabilityResolverService::class)->resolve($this->doctorId, $this->day->copy(), 1, ['duration_minutes' => 60]);

    expect($result['source'])->toBe('smd')
        ->and(collect($result['schedule'])->pluck('slots')->flatten())->toBeEmpty();
});

/*
| T-6: vincular doctores con 1 consulta, sin pisar ids cargados a mano (D-9).
*/

/** El mismo nombre que el doctor de Sofo, con otro `_id` en SMD. */
function singleNamesake(string $doctorName, string $smdId, Carbon $from, Carbon $to): array
{
    $item = auditSmdAvailability($smdId, $from, $to)[0];
    [$first, $last] = explode(' ', $doctorName, 2);
    $item['physician'] = ['_id' => $smdId, 'name' => $first, 'lastName' => $last, 'email' => 'tocayo@smd.test'];

    return $item;
}

it('un id cargado a mano no se pisa aunque falte el email y SMD tenga al mismo nombre con otro id', function () {
    $name = 'Zq Manual'.uniqid().' Doctora';
    DB::table('doctors')->where('id', $this->doctorId)->update(['name' => $name, 'email' => null]);
    $from = $this->day->copy()->setTime(9, 0);
    $to = $this->day->copy()->setTime(12, 0);
    $this->smd['availability'] = array_merge(
        auditSmdAvailability($this->uid, $from, $to),
        [singleNamesake($name, 'otro-id-'.uniqid(), $from, $to)],
    );

    $result = singleBook($this->doctorId, $this->person->id, $this->day->copy()->setTime(10, 0));

    expect($result['activity_id'])->not->toBeNull()
        ->and(DB::table('doctors')->where('id', $this->doctorId)->value('unique_id'))->toBe($this->uid);
});

it('un doctor sin id se vincula con UNA consulta a General, no una por especialidad', function () {
    $name = 'Zq Nuevo'.uniqid().' Doctor';
    $smdId = 'nuevo-id-'.uniqid();
    DB::table('doctors')->where('id', $this->doctorId)->update(['name' => $name, 'unique_id' => null, 'email' => null]);
    $this->smd['availability'] = [singleNamesake($name, $smdId, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(12, 0))];

    singleBook($this->doctorId, $this->person->id, $this->day->copy()->setTime(10, 0));
    $consultas = collect(auditSent('/schedule/availability'))->map(fn ($r) => urldecode($r->url()));

    // 1 para vincular + 1 para validar el horario (antes: 3 + 1).
    expect(DB::table('doctors')->where('id', $this->doctorId)->value('unique_id'))->toBe($smdId)
        ->and($consultas)->toHaveCount(2)
        ->and($consultas->every(fn ($u) => str_contains($u, 'specialty=General')))->toBeTrue();
});

it('doctors:link-smd --dry-run hace UNA consulta y no pide la lista de especialidades', function () {
    $this->smd['availability'] = auditSmdAvailability($this->uid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(12, 0));

    $this->artisan('doctors:link-smd', ['--dry-run' => true])->assertExitCode(0);

    expect(auditSent('/schedule/availability'))->toHaveCount(1)
        ->and(auditSent('/specialt'))->toHaveCount(0);
});
