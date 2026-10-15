<?php

/*
|--------------------------------------------------------------------------
| Medidor de consultas a la API de ShareMeData (T1)
|--------------------------------------------------------------------------
| 2026-10-12: un proceso programado hizo ~5.700 consultas en 17 h y SMD cortó
| el acceso. Toda consulta pasa por ShareMeDataService y se cuenta (por día,
| mes y origen). planning/15-consumo-api-smd.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\AvailabilityResolverService;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Admin\Services\Smd\ApiBudget;

beforeEach(function () {
    $this->smd = auditBootFakes();
    $this->budget = app(ApiBudget::class);
    $this->today = now(config('app.timezone'))->toDateString();

    // Contadores limpios para el día (el caché de tests puede venir con datos).
    foreach (array_keys(Cache::get("smd:usage:day:{$this->today}:sources", [])) as $key) {
        Cache::forget("smd:usage:day:{$this->today}:src:".md5($key));
    }
    Cache::forget("smd:usage:day:{$this->today}:sources");
    Cache::forget("smd:usage:day:{$this->today}");
    Cache::forget('smd:usage:month:'.substr($this->today, 0, 7));
    Cache::forget("smd:usage:day:{$this->today}:blocked");
    Cache::forget("smd:usage:warned:cap:{$this->today}");
});

it('cada consulta a SMD suma 1 al día y al mes, con su método', function () {
    $smd = app(ShareMeDataService::class);

    $smd->searchPatientByCi('1234567');
    $smd->getPatient('abc123');

    expect($this->budget->today())->toBe(2)
        ->and($this->budget->month())->toBe(2)
        ->and(array_values($this->budget->bySource($this->today)))->toBe([1, 1])
        ->and(implode(' ', array_keys($this->budget->bySource($this->today))))
        ->toContain('searchPatientByCi')->toContain('getPatient');
});

it('registra QUIÉN pidió la consulta (el origen fuera de ShareMeDataService)', function () {
    $doctorId = auditDoctor(['name' => 'Dra Medidor '.uniqid()]);
    auditShift($doctorId, Carbon::tomorrow(config('app.timezone')));

    app(AvailabilityResolverService::class)->resolve($doctorId, Carbon::tomorrow(config('app.timezone')), 1, ['specialty' => 'General']);

    expect($this->budget->bySource($this->today))->toHaveKey('AvailabilityResolverService::resolve → checkAvailability')
        ->and($this->budget->today())->toBe(1);
});

it('una consulta que falla también cuenta (SMD la cobra igual)', function () {
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::fake(['*' => Http::response(['error' => 'forbidden'], 403)]);

    app(ShareMeDataService::class)->searchPatientByCi('1234567');

    expect($this->budget->today())->toBe(1);
});

it('las fichas pedidas en paralelo cuentan una por paciente', function () {
    app(ShareMeDataService::class)->getPatientsMany(['a1', 'b2', 'c3']);

    expect($this->budget->today())->toBe(3)
        ->and($this->budget->bySource($this->today))->toContain(3);
});

it('smd:usage muestra el día, el mes y los orígenes', function () {
    app(ShareMeDataService::class)->searchPatientByCi('1234567');

    $this->artisan('smd:usage', ['--days' => 3])
        ->expectsOutputToContain('de 10.000 contratadas')
        ->expectsOutputToContain('searchPatientByCi')
        ->assertSuccessful();
});

/*
| T2 · Tope diario (800) y aviso mensual
*/

function fillToday(int $n): void
{
    $day = now(config('app.timezone'))->toDateString();
    Cache::put("smd:usage:day:{$day}", $n, now()->addDay());
}

it('debajo del tope, toda consulta sale', function () {
    fillToday(799);

    app(ShareMeDataService::class)->searchPatientByCi('1234567');

    expect(auditSent('/patients'))->toHaveCount(1)
        ->and($this->budget->today())->toBe(800);
});

it('pasado el tope, una consulta no esencial NO sale, no se cuenta y responde vacío', function () {
    fillToday(800);
    \Illuminate\Support\Facades\Log::spy();

    $result = app(ShareMeDataService::class)->searchPatientByCi('1234567');

    expect($result)->toBe([])
        ->and(auditSent('/patients'))->toHaveCount(0)
        ->and($this->budget->today())->toBe(800)
        ->and($this->budget->blocked($this->today))->toBe(1);

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => str_contains($msg, 'Se llegó al tope diario'))->once();
});

it('pasado el tope, crear o actualizar el evento de una cita sale igual (esencial)', function () {
    fillToday(800);

    app(ShareMeDataService::class)->updateEvent('evt-1', ['status' => 'CONFIRMED']);

    expect(auditSent('/events/evt-1'))->toHaveCount(1)
        ->and($this->budget->today())->toBe(801);
});

it('pasado el tope, la ficha de un paciente nuevo de Dropbox sale igual (esencial)', function () {
    fillToday(800);

    app(\Webkul\Admin\Services\SmdPatientDirectory::class)->patient('pac-'.uniqid());

    expect($this->budget->today())->toBe(801)
        ->and($this->budget->blocked($this->today))->toBe(0);
});

it('pasado el tope, agendar desde el CRM valida la disponibilidad igual (esencial)', function () {
    expect($this->budget->isEssential('checkAvailability', 'AppointmentService::process'))->toBeTrue()
        ->and($this->budget->isEssential('searchPatient', 'SmdAppointmentPush::ensureSmdPatientId'))->toBeTrue()
        // Horarios del formulario de WhatsApp y búsquedas: no esenciales.
        ->and($this->budget->isEssential('checkAvailability', 'AvailabilityResolverService::resolve'))->toBeFalse()
        ->and($this->budget->isEssential('searchPatientOrNull', 'SmdPatientLink::lookup'))->toBeFalse();
});

it('las fichas en paralelo también respetan el tope', function () {
    fillToday(799);

    // Desde fuera de los servicios esenciales: no esencial, 3 fichas no entran en 1 lugar.
    $result = app(ShareMeDataService::class)->getPatientsMany(['a1', 'b2', 'c3']);

    expect($result)->toBe([])
        ->and($this->budget->blocked($this->today))->toBe(3);
});

it('avisa una sola vez por día cuando el mes pasa las 8.000', function () {
    Cache::put('smd:usage:month:'.substr($this->today, 0, 7), 7999, now()->addDay());
    Cache::forget("smd:usage:warned:month:{$this->today}");
    \Illuminate\Support\Facades\Log::spy();

    $smd = app(ShareMeDataService::class);
    $smd->searchPatientByCi('1');
    $smd->searchPatientByCi('2');

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => str_contains($msg, 'pasó el aviso'))->once();
});

it('el tope se configura con SMD_DAILY_CAP', function () {
    config(['smd.api_budget.daily_cap' => 5]);
    fillToday(5);

    expect(app(ShareMeDataService::class)->searchPatientByCi('1234567'))->toBe([])
        ->and(auditSent('/patients'))->toHaveCount(0);
});

/*
| T3 · Sin procesos programados contra SMD
*/

it('no queda ninguna tarea programada que consulte SMD (el catálogo se quitó)', function () {
    $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->implode("\n");

    expect($commands)->not->toContain('booking-catalog')
        ->and(\Illuminate\Support\Facades\Artisan::all())->not->toHaveKey('whatsapp:booking-catalog')
        ->and(class_exists(\Webkul\Admin\Jobs\RefreshBookingSlots::class))->toBeFalse();
});

it('cancelar o mover una cita ya no dispara consultas de disponibilidad a SMD', function () {
    $doctorId = auditDoctor(['name' => 'Dra Sin Refresco '.uniqid()]);
    $cita = auditAppointment($doctorId, auditPerson()->id, Carbon::tomorrow(config('app.timezone'))->setTime(10, 0), 'evt-'.uniqid());

    app(\Webkul\Admin\Services\PatientAppointments::class)->cancel($cita['activity_id']);

    expect(auditSent('/schedule/availability'))->toHaveCount(0);
});

it('las tareas programadas son solo las revisadas: una nueva hace fallar esta prueba', function () {
    $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($event) => preg_replace('/^.*artisan.? /', '', (string) $event->command))
        ->map(fn ($command) => strtok(trim($command, "'\" "), ' '))
        ->sort()->values()->all();

    // Revisadas el 2026-10-15: solo smd:sync-dropbox puede llamar a SMD, y
    // únicamente por pacientes nuevos. Agregar una tarea exige revisar su
    // costo en consultas a SMD y sumarla acá.
    expect($commands)->toBe([
        'campaign:process',
        'inbound-emails:process',
        'queue:work',
        'smd:sync-dropbox',
        'whatsapp:appointment-reminders',
        'whatsapp:reactivate-agent',
    ]);
});

it('los recordatorios y la reactivación del agente no consultan SMD', function () {
    \Illuminate\Support\Facades\Artisan::call('whatsapp:appointment-reminders');
    \Illuminate\Support\Facades\Artisan::call('whatsapp:reactivate-agent');

    expect(auditSent('smd.test'))->toHaveCount(0);
});

it('una sincronización de Dropbox sin eventos nuevos no consulta SMD', function () {
    \Illuminate\Support\Facades\Artisan::call('smd:sync-dropbox', ['--days' => 2]);

    expect(auditSent('smd.test'))->toHaveCount(0)
        ->and(auditSent('dropbox'))->not->toBeEmpty();
});
