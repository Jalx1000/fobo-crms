<?php

/*
|--------------------------------------------------------------------------
| Alertas de la integración SMD/Dropbox (planning/16, RF-10, D-8, D-11)
|--------------------------------------------------------------------------
| Un correo por tipo de error por hora, a SMD_ALERT_EMAILS.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Webkul\Admin\Mail\SmdIntegrationAlert;
use Webkul\Admin\Services\DropboxService;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Admin\Services\Smd\ApiBudget;
use Webkul\Admin\Services\Smd\QuotaExceeded;
use Webkul\Admin\Services\Smd\SmdAlerts;

function forgetAlerts(): void
{
    foreach (array_keys(SmdAlerts::TYPES) as $type) {
        foreach (['since', 'count', 'sent'] as $suffix) {
            Cache::forget("smd:alert:{$type}:{$suffix}");
        }
    }
}

beforeEach(function () {
    Mail::fake();
    config(['smd.api_budget.alerts.emails' => ['uno@test.local', 'dos@test.local']]);
    forgetAlerts();
});

afterEach(fn () => forgetAlerts());

it('dos errores del mismo tipo en la misma hora mandan UN correo, a SMD_ALERT_EMAILS', function () {
    app(SmdAlerts::class)->report(SmdAlerts::AVAILABILITY, ['status' => 400]);
    app(SmdAlerts::class)->report(SmdAlerts::AVAILABILITY, ['status' => 400]);

    Mail::assertQueued(SmdIntegrationAlert::class, 1);
    Mail::assertQueued(SmdIntegrationAlert::class, fn ($m) => $m->hasTo('uno@test.local') && $m->hasTo('dos@test.local')
        && $m->type === 'A-1' && $m->count === 1);
});

it('tipos distintos mandan un correo cada uno', function () {
    app(SmdAlerts::class)->report(SmdAlerts::AVAILABILITY);
    app(SmdAlerts::class)->report(SmdAlerts::AUTH);

    Mail::assertQueued(SmdIntegrationAlert::class, 2);
});

it('pasada la hora vuelve a avisar, con cuántas veces pasó', function () {
    $alerts = app(SmdAlerts::class);
    $alerts->report(SmdAlerts::QUOTA);
    $alerts->report(SmdAlerts::QUOTA);
    Cache::forget('smd:alert:A-5:sent'); // venció la hora

    $alerts->report(SmdAlerts::QUOTA);

    Mail::assertQueued(SmdIntegrationAlert::class, 2);
    Mail::assertQueued(SmdIntegrationAlert::class, fn ($m) => $m->count === 3);
});

it('sin destinatarios solo queda en el log', function () {
    config(['smd.api_budget.alerts.emails' => []]);

    app(SmdAlerts::class)->report(SmdAlerts::DROPBOX);

    Mail::assertNothingQueued();
});

it('el correo dice qué falló, desde cuándo, cuántas veces y qué revisar', function () {
    $html = (new SmdIntegrationAlert('A-2', 'SMD rechaza la apikey (401/403)', 'Revisar SMD_API_KEY', '2026-10-28 10:00:00', 4, ['status' => 401, 'endpoint' => null]))->render();

    expect($html)->toContain('A-2')->toContain('SMD rechaza la apikey')
        ->toContain('2026-10-28 10:00:00')->toContain('>4<')->toContain('Revisar SMD_API_KEY')->toContain('401');
});

/*
| T-9: dónde se dispara cada alerta.
*/

function alertTypes(): array
{
    return Mail::queued(SmdIntegrationAlert::class)->map(fn ($m) => $m->type)->sort()->values()->all();
}

function agendaNow(): ?array
{
    return app(ShareMeDataService::class)->agenda('2031-03-03 00:00:00', '2031-03-03 23:59:59');
}

it('A-1: la disponibilidad responde 400', function () {
    $smd = auditBootFakes();
    $smd['availability'] = Http::response(['error' => 'bad'], 400);

    agendaNow();

    expect(alertTypes())->toBe(['A-1']);
});

it('A-1: la disponibilidad no responde (sin tiempo límite de pantalla)', function () {
    auditBootFakes();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

    agendaNow();

    expect(alertTypes())->toBe(['A-1']);
});

it('sin alerta si la cortó el tiempo de la pantalla de WhatsApp (la termina un job)', function () {
    auditBootFakes();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

    app(ShareMeDataService::class)->agenda('2031-03-03 00:00:00', '2031-03-03 23:59:59', 3);

    Mail::assertNothingQueued();
});

it('A-2: SMD rechaza la apikey (y no se cuenta además como A-1)', function () {
    $smd = auditBootFakes();
    $smd['availability'] = Http::response(['error' => 'unauthorized'], 401);

    agendaNow();

    expect(alertTypes())->toBe(['A-2']);
});

it('A-3: createEvent falla con 500; con "horario ocupado" (409) no avisa', function () {
    $smd = auditBootFakes();

    $smd['createEvent'] = Http::response(['message' => 'slot not available'], 409);
    app(ShareMeDataService::class)->createEvent(['summary' => 'x']);
    Mail::assertNothingQueued();

    $smd['createEvent'] = Http::response(['message' => 'internal'], 500);
    app(ShareMeDataService::class)->createEvent(['summary' => 'x']);

    expect(alertTypes())->toBe(['A-3']);
});

it('A-3: updateEvent falla con 500', function () {
    $smd = auditBootFakes();
    $smd['updateEvent'] = Http::response(['message' => 'internal'], 500);

    app(ShareMeDataService::class)->updateEvent('evt-1', ['summary' => 'x']);

    Mail::assertQueued(SmdIntegrationAlert::class, fn ($m) => $m->type === 'A-3' && $m->context['evento_smd'] === 'evt-1');
});

it('A-4: no se pudo renovar el token de Dropbox', function () {
    config(['smd.dropbox.refresh_token' => 'r', 'smd.dropbox.app_key' => 'k', 'smd.dropbox.app_secret' => 's']);
    Cache::forget('dropbox_access_token');
    Http::fake(['*oauth2/token' => Http::response(['error' => 'invalid_grant'], 400), '*' => Http::response([], 200)]);

    $token = (new ReflectionMethod(DropboxService::class, 'token'));
    $token->setAccessible(true);
    $token->invoke(app(DropboxService::class));

    expect(alertTypes())->toBe(['A-4']);
});

it('A-4: la sync del webhook termina con errores', function () {
    DB::table('settings')->updateOrInsert(['key' => 'dropbox_cursor'], ['value' => 'cursor-test', 'updated_at' => now()]);

    $dropbox = Mockery::mock(DropboxService::class);
    $dropbox->shouldReceive('listChanges')->andReturn(['entries' => [['name' => 'event-x']], 'cursor' => 'cursor-test', 'has_more' => false]);
    $sync = Mockery::mock(\Webkul\Admin\Services\SmdAppointmentSyncService::class);
    $sync->shouldReceive('emptySummary')->andReturn(['creados' => 0, 'actualizados' => 0, 'cancelados' => 0, 'sin_cambios' => 0, 'obsoletos' => 0, 'errores' => 0]);
    $sync->shouldReceive('processEntries')->andReturnUsing(function ($entries, &$summary) {
        $summary['errores'] = 2;
    });

    (new \Webkul\Admin\Jobs\ProcessDropboxNotification)->handle($dropbox, $sync);

    Mail::assertQueued(SmdIntegrationAlert::class, fn ($m) => $m->type === 'A-4' && $m->context['errores'] === 2);
});

it('A-5: se llegó al tope diario de consultas', function () {
    config(['smd.api_budget.daily_cap' => 1, 'smd.api_budget.essential_endpoints' => [], 'smd.api_budget.essential_sources' => []]);
    $budget = app(ApiBudget::class);
    $budget->authorize('agenda', 'Test');

    expect(fn () => $budget->authorize('agenda', 'Test'))->toThrow(QuotaExceeded::class);
    expect(alertTypes())->toBe(['A-5']);
});
