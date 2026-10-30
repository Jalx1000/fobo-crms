<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Webkul\Admin\Jobs\SyncSmdAppointmentsJob;
use Webkul\Admin\Services\DropboxService;
use Webkul\Admin\Services\IncomingAppointmentService;
use Webkul\Admin\Services\SmdAppointmentSyncService;
use Webkul\Admin\Support\SmdSyncState;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

beforeEach(function () {
    Cache::forget(SyncSmdAppointmentsJob::STATUS_KEY);
    Cache::forget(SyncSmdAppointmentsJob::LOCK_KEY);
    Cache::forget(SmdSyncState::LAST_FINISHED_KEY);
    SmdSyncState::setPaused(false);
});

/**
 * Bindea mocks de Dropbox e IncomingAppointment y devuelve el servicio resuelto.
 */
function makeSyncService(Mockery\MockInterface $dropbox, Mockery\MockInterface $incoming): SmdAppointmentSyncService
{
    app()->instance(DropboxService::class, $dropbox);
    app()->instance(IncomingAppointmentService::class, $incoming);

    return app(SmdAppointmentSyncService::class);
}

// ─── Servicio ────────────────────────────────────────────────────────────────

it('crea una cita nueva y registra el evento sincronizado', function () {
    $dropbox = Mockery::mock(DropboxService::class);
    $dropbox->shouldReceive('listFilesForDate')->andReturn([['path_lower' => '/smd-events/ext-1.json']], []);
    $dropbox->shouldReceive('downloadJson')->andReturn(['_id' => 'ext-1', 'status' => '']);

    $incoming = Mockery::mock(IncomingAppointmentService::class);
    $incoming->shouldReceive('processDropbox')->once()->andReturn(['activity_id' => 1, 'lead_id' => 1]);

    $summary = makeSyncService($dropbox, $incoming)->run(1);

    expect($summary['creados'])->toBe(1);
    $this->assertDatabaseHas('smd_synced_events', ['external_id' => 'ext-1']);
});

it('cancela una cita archivada existente', function () {
    DB::table('smd_synced_events')->insert([
        'external_id' => 'ext-cancel', 'raw_payload' => '{}', 'status' => '',
        'created_at'  => now(), 'updated_at' => now(),
    ]);

    $dropbox = Mockery::mock(DropboxService::class);
    $dropbox->shouldReceive('listFilesForDate')->andReturn([['path_lower' => '/x.json']], []);
    $dropbox->shouldReceive('downloadJson')->andReturn(['_id' => 'ext-cancel', 'archived' => true]);

    $incoming = Mockery::mock(IncomingAppointmentService::class);
    $incoming->shouldReceive('cancelDropbox')->once();

    $summary = makeSyncService($dropbox, $incoming)->run(1);

    expect($summary['cancelados'])->toBe(1);
});

it('omite un evento sin cambios (mismo hash)', function () {
    $payload = ['_id' => 'ext-same', 'status' => ''];

    DB::table('smd_synced_events')->insert([
        'external_id' => 'ext-same', 'raw_payload' => json_encode($payload), 'status' => '',
        'created_at'  => now(), 'updated_at' => now(),
    ]);

    $dropbox = Mockery::mock(DropboxService::class);
    $dropbox->shouldReceive('listFilesForDate')->andReturn([['path_lower' => '/x.json']], []);
    $dropbox->shouldReceive('downloadJson')->andReturn($payload);

    $incoming = Mockery::mock(IncomingAppointmentService::class);
    $incoming->shouldReceive('processDropbox')->never();
    $incoming->shouldReceive('updateDropbox')->never();

    $summary = makeSyncService($dropbox, $incoming)->run(1);

    expect($summary['sin_cambios'])->toBe(1);
});

// ─── Job ─────────────────────────────────────────────────────────────────────

it('el job publica estado done y libera el lock al terminar', function () {
    Cache::put(SyncSmdAppointmentsJob::LOCK_KEY, 'x', 600);

    $service = Mockery::mock(SmdAppointmentSyncService::class);
    $service->shouldReceive('run')->once()->andReturn([
        'creados' => 2, 'actualizados' => 1, 'cancelados' => 0, 'sin_cambios' => 3, 'errores' => 0,
    ]);

    (new SyncSmdAppointmentsJob(7))->handle($service);

    $status = Cache::get(SyncSmdAppointmentsJob::STATUS_KEY);
    expect($status['state'])->toBe('done');
    expect($status['summary']['creados'])->toBe(2);
    expect(Cache::has(SyncSmdAppointmentsJob::LOCK_KEY))->toBeFalse();
});

// ─── Endpoint ────────────────────────────────────────────────────────────────

it('despacha el job y deja estado running (202)', function () {
    Queue::fake();

    $this->actingAs(User::find(1), 'user')
        ->post(route('admin.activities.smd.sync'))
        ->assertStatus(202)
        ->assertJson(['state' => 'running']);

    Queue::assertPushed(SyncSmdAppointmentsJob::class);
    expect(Cache::get(SyncSmdAppointmentsJob::STATUS_KEY)['state'])->toBe('running');
});

it('rechaza con 409 si ya hay un sync en curso', function () {
    Queue::fake();
    Cache::add(SyncSmdAppointmentsJob::LOCK_KEY, 'en-curso', 600);

    $this->actingAs(User::find(1), 'user')
        ->post(route('admin.activities.smd.sync'))
        ->assertStatus(409)
        ->assertJson(['state' => 'running']);

    Queue::assertNotPushed(SyncSmdAppointmentsJob::class);
});

it('devuelve el estado actual del sync con el flag paused', function () {
    Cache::put(SyncSmdAppointmentsJob::STATUS_KEY, [
        'state'   => 'done',
        'summary' => ['creados' => 1, 'actualizados' => 0, 'cancelados' => 0, 'sin_cambios' => 0, 'errores' => 0],
    ], 600);

    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.activities.smd.sync.status'))
        ->assertStatus(200)
        ->assertJson(['state' => 'done', 'paused' => false])
        ->assertJsonStructure(['state', 'message', 'paused']);
});

// ─── Pausa ───────────────────────────────────────────────────────────────────

it('pausa y reanuda la sincronización automática', function () {
    $this->actingAs(User::find(1), 'user')
        ->post(route('admin.activities.smd.pause'), ['paused' => true])
        ->assertStatus(200)
        ->assertJson(['paused' => true]);

    expect(SmdSyncState::isPaused())->toBeTrue();

    $this->actingAs(User::find(1), 'user')
        ->post(route('admin.activities.smd.pause'), ['paused' => false])
        ->assertStatus(200)
        ->assertJson(['paused' => false]);

    expect(SmdSyncState::isPaused())->toBeFalse();
});

it('omite el sync automático cuando está pausado', function () {
    Queue::fake();
    SmdSyncState::setPaused(true);

    $this->actingAs(User::find(1), 'user')
        ->post(route('admin.activities.smd.sync'), ['auto' => true])
        ->assertStatus(200)
        ->assertJson(['state' => 'paused', 'skipped' => true]);

    Queue::assertNotPushed(SyncSmdAppointmentsJob::class);
});

it('el sync manual se ejecuta aunque esté pausado', function () {
    Queue::fake();
    SmdSyncState::setPaused(true);

    $this->actingAs(User::find(1), 'user')
        ->post(route('admin.activities.smd.sync')) // sin auto = manual
        ->assertStatus(202);

    Queue::assertPushed(SyncSmdAppointmentsJob::class);
});
