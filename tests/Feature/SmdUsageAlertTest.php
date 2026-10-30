<?php

/*
|--------------------------------------------------------------------------
| Alertas de consumo diario de la API de SMD
|--------------------------------------------------------------------------
| A las 300 consultas del día: aviso al personal de OdontoKing y Sofopolis.
| A las 500: alerta de peligro (en rojo). Una vez por día y por nivel.
*/

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Webkul\Admin\Mail\SmdUsageAlert;
use Webkul\Admin\Services\Smd\ApiBudget;

beforeEach(function () {
    Mail::fake();
    $this->budget = app(ApiBudget::class);
    $this->today = now(config('app.timezone'))->toDateString();

    foreach (["smd:usage:day:{$this->today}", 'smd:usage:month:'.substr($this->today, 0, 7), "smd:usage:alert:{$this->today}:warning", "smd:usage:alert:{$this->today}:danger"] as $key) {
        Cache::forget($key);
    }

    config([
        'smd.api_budget.alerts.warning_daily' => 300,
        'smd.api_budget.alerts.danger_daily'  => 500,
        'smd.api_budget.alerts.emails'        => ['personal@odontoking.test', 'equipo@sofopolis.test'],
    ]);
});

it('debajo de 300 consultas no avisa', function () {
    $this->budget->record('getPatient', 'SmdPatientDirectory::patient', 299);

    Mail::assertNothingQueued();
});

it('a las 300 consultas avisa una sola vez a OdontoKing y Sofopolis', function () {
    $this->budget->record('getPatient', 'SmdPatientDirectory::patient', 299);
    $this->budget->record('getPatient', 'SmdPatientDirectory::patient');
    $this->budget->record('getPatient', 'SmdPatientDirectory::patient', 10);

    Mail::assertQueued(SmdUsageAlert::class, 1);
    Mail::assertQueued(SmdUsageAlert::class, fn ($mail) => $mail->level === 'warning'
        && $mail->count === 300
        && $mail->hasTo('personal@odontoking.test')
        && $mail->hasTo('equipo@sofopolis.test'));
});

it('a las 500 consultas manda la alerta de peligro, en rojo', function () {
    $this->budget->record('getPatient', 'SmdPatientDirectory::patient', 300);
    $this->budget->record('getPatient', 'SmdPatientDirectory::patient', 200);
    $this->budget->record('getPatient', 'SmdPatientDirectory::patient', 50);

    Mail::assertQueued(SmdUsageAlert::class, 2);
    Mail::assertQueued(SmdUsageAlert::class, function ($mail) {
        if ($mail->level !== 'danger') {
            return false;
        }

        $html = $mail->render();

        return $mail->count === 500 && str_contains($html, '#B91C1C') && str_contains($html, 'PELIGRO');
    });
});

it('si el día salta de golpe a más de 500, manda solo la de peligro', function () {
    $this->budget->record('getPatient', 'SmdPatientDirectory::patient', 520);

    Mail::assertQueued(SmdUsageAlert::class, 1);
    Mail::assertQueued(SmdUsageAlert::class, fn ($mail) => $mail->level === 'danger');
});

it('el correo dice de dónde salen las consultas', function () {
    $this->budget->record('searchPatientByName', 'SmdPatientDirectory::resolve', 300);

    Mail::assertQueued(SmdUsageAlert::class, fn ($mail) => str_contains($mail->render(), 'SmdPatientDirectory::resolve'));
});

it('sin correos configurados no manda nada, pero lo deja en el log', function () {
    config(['smd.api_budget.alerts.emails' => []]);

    $this->budget->record('getPatient', 'SmdPatientDirectory::patient', 300);

    Mail::assertNothingQueued();
    expect(Cache::has("smd:usage:alert:{$this->today}:warning"))->toBeTrue();
});
