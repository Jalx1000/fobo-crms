<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\AvailabilityResolverService;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Doctor\Models\Doctor;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tz = config('app.timezone');
    $this->date = Carbon::today()->addDay()->toDateString();
});

/**
 * Construye la respuesta de checkAvailability() en el formato real de SMD:
 * un array con un mapa { "Y-m-d": [ {start, end}, ... ] }, intervalos de 15 min.
 */
function smdSlots(string $date, string $tz, int $startHour = 8, int $endHour = 10): array
{
    $intervals = [];
    $cursor = Carbon::parse("$date $startHour:00", $tz);
    $end = Carbon::parse("$date $endHour:00", $tz);

    while ($cursor->lt($end)) {
        $next = $cursor->copy()->addMinutes(15);
        $intervals[] = ['start' => $cursor->toIso8601String(), 'end' => $next->toIso8601String()];
        $cursor = $next;
    }

    return [[$date => $intervals]];
}

function makeDoctor(bool $linked): Doctor
{
    return Doctor::create([
        'name'      => 'Dr Test '.uniqid(),
        'is_active' => true,
        'unique_id' => $linked ? 'phys-'.uniqid() : null,
    ]);
}

function insertShift(int $doctorId, string $date): void
{
    DB::table('doctor_shifts')->insert([
        'doctor_id'  => $doctorId,
        'date'       => $date,
        'start_time' => '08:00',
        'end_time'   => '10:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function startTimes(array $result): array
{
    return array_map(fn ($s) => $s['start_time'], $result['schedule'][0]['slots']);
}

it('devuelve slots de SMD restando las citas locales (source=smd)', function () {
    $doctor = makeDoctor(linked: true);
    insertShift($doctor->id, $this->date); // jornada local 08:00–10:00

    // Cita local 08:30–09:00 que debe quitar esos intervalos.
    $activityId = DB::table('activities')->insertGetId([
        'title'         => 'Cita local',
        'type'          => 'meeting',
        'schedule_from' => "{$this->date} 08:30:00",
        'schedule_to'   => "{$this->date} 09:00:00",
        'is_done'       => 0,
        'user_id'       => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
    DB::table('doctor_activities')->insert(['doctor_id' => $doctor->id, 'activity_id' => $activityId]);

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldReceive('checkAvailability')->andReturn(smdSlots($this->date, $this->tz));
    $mock->shouldReceive('getLastResponse')->andReturn(['status' => 200, 'body' => []]);
    app()->instance(ShareMeDataService::class, $mock);

    $result = app(AvailabilityResolverService::class)
        ->resolve($doctor->id, Carbon::parse($this->date), 1, ['duration_minutes' => 30]);

    expect($result['source'])->toBe('smd');
    expect($result['degraded'])->toBeFalse();
    // 08:00–10:00 menos 08:30–09:00, en bloques de 30 min.
    expect(startTimes($result))->toBe(['08:00', '09:00', '09:30']);
});

it('hace fallback local cuando el doctor no tiene unique_id', function () {
    $doctor = makeDoctor(linked: false);
    insertShift($doctor->id, $this->date);

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldNotReceive('checkAvailability'); // no debe tocar SMD
    app()->instance(ShareMeDataService::class, $mock);

    $result = app(AvailabilityResolverService::class)
        ->resolve($doctor->id, Carbon::parse($this->date), 1, ['duration_minutes' => 60]);

    expect($result['source'])->toBe('local');
    expect($result['degraded'])->toBeTrue();
    expect($result['reason'])->toBe('doctor_unlinked');
    expect(startTimes($result))->toBe(['08:00', '09:00']); // shifts 08–10 en bloques de 60
});

it('hace fallback local cuando SMD está caído', function () {
    $doctor = makeDoctor(linked: true);
    insertShift($doctor->id, $this->date);

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldReceive('checkAvailability')->andReturn([]);
    $mock->shouldReceive('getLastResponse')->andReturn(null); // null = excepción de red
    app()->instance(ShareMeDataService::class, $mock);

    $result = app(AvailabilityResolverService::class)
        ->resolve($doctor->id, Carbon::parse($this->date), 1, ['duration_minutes' => 60]);

    expect($result['source'])->toBe('local');
    expect($result['reason'])->toBe('smd_unavailable');
    expect(startTimes($result))->toBe(['08:00', '09:00']);
});

it('hace fallback local cuando smd.validate_availability es false', function () {
    config(['smd.validate_availability' => false]);

    $doctor = makeDoctor(linked: true);
    insertShift($doctor->id, $this->date);

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldNotReceive('checkAvailability');
    app()->instance(ShareMeDataService::class, $mock);

    $result = app(AvailabilityResolverService::class)
        ->resolve($doctor->id, Carbon::parse($this->date), 1, ['duration_minutes' => 60]);

    expect($result['source'])->toBe('local');
    expect($result['reason'])->toBe('smd_disabled');
});

it('recorta la disponibilidad de SMD a la jornada local (intersección)', function () {
    $doctor = makeDoctor(linked: true);
    insertShift($doctor->id, $this->date); // jornada local 08:00–10:00

    // SMD libre 07:00–09:00: el tramo 07:00–08:00 cae fuera de la jornada.
    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldReceive('checkAvailability')->andReturn(smdSlots($this->date, $this->tz, 7, 9));
    $mock->shouldReceive('getLastResponse')->andReturn(['status' => 200, 'body' => []]);
    app()->instance(ShareMeDataService::class, $mock);

    $result = app(AvailabilityResolverService::class)
        ->resolve($doctor->id, Carbon::parse($this->date), 1, ['duration_minutes' => 60]);

    expect($result['source'])->toBe('smd');
    // 07:00–08:00 recortado por jornada → solo 08:00–09:00
    expect(startTimes($result))->toBe(['08:00']);
    expect($result['schedule'][0]['slots'][0]['end_time'])->toBe('09:00');
});

it('día sin turno local devuelve cerrado aunque SMD tenga disponibilidad', function () {
    $doctor = makeDoctor(linked: true); // SIN insertShift → día cerrado

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldReceive('checkAvailability')->andReturn(smdSlots($this->date, $this->tz));
    $mock->shouldReceive('getLastResponse')->andReturn(['status' => 200, 'body' => []]);
    app()->instance(ShareMeDataService::class, $mock);

    $result = app(AvailabilityResolverService::class)
        ->resolve($doctor->id, Carbon::parse($this->date), 1, ['duration_minutes' => 60]);

    expect($result['source'])->toBe('smd');
    expect($result['schedule'][0]['slots'])->toBe([]);
});

it('respeta SMD vacío legítimo (200 sin physician) sin caer a fallback local', function () {
    $doctor = makeDoctor(linked: true);
    insertShift($doctor->id, $this->date); // existen shifts, pero NO deben usarse

    $mock = \Mockery::mock(ShareMeDataService::class);
    $mock->shouldReceive('checkAvailability')->andReturn([]); // physician no encontrado
    $mock->shouldReceive('getLastResponse')->andReturn(['status' => 200, 'body' => []]);
    app()->instance(ShareMeDataService::class, $mock);

    $result = app(AvailabilityResolverService::class)
        ->resolve($doctor->id, Carbon::parse($this->date), 1);

    expect($result['source'])->toBe('smd');
    expect($result['degraded'])->toBeFalse();
    expect($result['schedule'][0]['slots'])->toBe([]); // vacío, NO los shifts locales
});
