<?php

/*
|--------------------------------------------------------------------------
| smd:check-doctors (planning/16, RF-9, A-6 y A-7)
|--------------------------------------------------------------------------
| La BD de pruebas es un clon con doctores reales: se mira que los doctores
| del test estén (o no) en el aviso, no la lista entera.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Webkul\Admin\Mail\SmdIntegrationAlert;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

function checkAlert(string $type): ?SmdIntegrationAlert
{
    return Mail::queued(SmdIntegrationAlert::class)->first(fn ($m) => $m->type === $type);
}

beforeEach(function () {
    $this->smd = auditBootFakes();
    Mail::fake();
    config(['smd.availability_mode' => 'single', 'smd.api_budget.alerts.emails' => ['alertas@test.local']]);
    Cache::forget('smd:agenda:'.now(config('app.timezone'))->toDateString().':14');

    $this->specialtyId = DB::table('specialties')->insertGetId([
        'name' => 'Zq Check '.uniqid(), 'slug' => 'zq-check-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->ok = auditDoctor(['name' => 'Zq Bien '.uniqid()]);
    DB::table('doctor_specialty')->insert(['doctor_id' => $this->ok, 'specialty_id' => $this->specialtyId]);
    $this->sinEspecialidad = auditDoctor(['name' => 'Zq SinEsp '.uniqid()]);
    $this->fueraDeSmd = auditDoctor(['name' => 'Zq Fuera '.uniqid()]);
    DB::table('doctor_specialty')->insert(['doctor_id' => $this->fueraDeSmd, 'specialty_id' => $this->specialtyId]);

    // SMD publica a los dos primeros (uno sin horarios: V-17), no al tercero.
    $uid = fn ($id) => DB::table('doctors')->where('id', $id)->value('unique_id');
    $day = Carbon::tomorrow(config('app.timezone'));
    $sinHorarios = auditSmdAvailability($uid($this->sinEspecialidad), $day, $day)[0];
    $this->smd['availability'] = array_merge(
        auditSmdAvailability($uid($this->ok), $day->copy()->setTime(9, 0), $day->copy()->setTime(10, 0)),
        [$sinHorarios],
    );
});

it('avisa A-6 (sin especialidad) y A-7 (no está en SMD) con UNA consulta', function () {
    $this->artisan('smd:check-doctors')->assertExitCode(0);

    $a6 = checkAlert('A-6');
    $a7 = checkAlert('A-7');

    expect($a6->context['doctores'])->toContain("#{$this->sinEspecialidad} ")
        ->and($a7->context['doctores'])->toContain("#{$this->fueraDeSmd} ")
        ->and(auditSent('/schedule/availability'))->toHaveCount(1);
});

it('un doctor bien (con especialidad y publicado, aunque sea sin horarios) no aparece', function () {
    $this->artisan('smd:check-doctors')->assertExitCode(0);

    expect(checkAlert('A-6')->context['doctores'])->not->toContain("#{$this->ok} ")
        ->and(checkAlert('A-7')->context['doctores'])->not->toContain("#{$this->ok} ")
        ->and(checkAlert('A-7')->context['doctores'])->not->toContain("#{$this->sinEspecialidad} ");
});

it('si SMD no responde no inventa A-7 y termina con error', function () {
    $this->smd['availability'] = Http::response(['error' => 'down'], 503);

    $this->artisan('smd:check-doctors')->assertExitCode(1);

    expect(checkAlert('A-7'))->toBeNull()
        ->and(checkAlert('A-1'))->not->toBeNull();
});

it('la ficha del doctor avisa solo cuando no tiene especialidades', function () {
    $admin = User::find(1);

    actingAs($admin, 'user')->get(route('admin.doctor.edit', $this->sinEspecialidad))
        ->assertOk()->assertSee('doctor-sin-especialidad', false);

    actingAs($admin, 'user')->get(route('admin.doctor.edit', $this->ok))
        ->assertOk()->assertDontSee('doctor-sin-especialidad', false);
});

it('el chequeo diario solo se programa en modo single', function () {
    $scheduled = fn () => collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'smd:check-doctors'));

    expect($scheduled())->not->toBeNull()
        ->and($scheduled()->filtersPass(app()))->toBeTrue();

    config(['smd.availability_mode' => 'per_specialty']);
    expect($scheduled()->filtersPass(app()))->toBeFalse();
});
