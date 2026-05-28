<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Jobs\ProcessDropboxNotification;
use Webkul\Admin\Services\DropboxService;
use Webkul\Admin\Services\IncomingAppointmentService;
use Webkul\Admin\Services\SmdStatusMapper;

uses(DatabaseTransactions::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeJobPayload(array $overrides = []): array
{
    $id = uniqid('job_ev_', true);

    return array_merge([
        '_id'         => $id,
        'archived'    => false,
        'status'      => '',
        'startDate'   => '2026-05-12T15:45:00.000Z',
        'endDate'     => '2026-05-12T16:15:00.000Z',
        'summary'     => 'Test Job Event',
        'owner'       => '6525b4de28cb7c0008b77dbb',
        'attendances' => [
            [
                '_id'      => 'pat-job-'.uniqid(),
                'name'     => 'Paciente',
                'type'     => 'patient',
                'lastName' => 'Job',
                'phone'    => '7'.rand(1000000, 9999999),
            ],
            [
                '_id'      => 'doc-job-'.uniqid(),
                'name'     => 'Doctor',
                'type'     => 'physician',
                'lastName' => 'Job',
                'phone'    => '',
            ],
        ],
    ], $overrides);
}

function dispatchJob(): void
{
    $job = new ProcessDropboxNotification;
    app()->call([$job, 'handle']);
}

beforeEach(function () {
    Cache::forget('dropbox_access_token');

    $stageId = DB::table('lead_pipeline_stages')->value('id') ?? 1;

    config([
        'smd.dropbox.access_token'        => 'fake-job-token',
        'smd.dropbox.refresh_token'       => null,
        'smd.dropbox.app_key'             => null,
        'smd.dropbox.app_secret'          => null,
        'smd.dropbox.appointments_folder' => '/smd-events',
        'smd.stage_map.confirmed'         => $stageId,
        'smd.stage_map.completed'         => $stageId,
        'smd.stage_map.cancelled'         => $stageId,
        'smd.stage_map.no_show'           => $stageId,
    ]);
});

// ===========================================================================
// Sin cursor en BD → early return, sin llamadas HTTP
// ===========================================================================

it('ProcessDropboxNotification no hace ninguna llamada HTTP cuando no hay cursor en BD', function () {
    // Asegurar que no existe cursor
    DB::table('settings')->where('key', 'dropbox_cursor')->delete();

    Http::fake(); // cualquier llamada HTTP fallaría el test

    dispatchJob();

    // Si llegamos aquí sin excepción ni llamadas HTTP → correcto
    Http::assertNothingSent();
});

it('ProcessDropboxNotification retorna sin error cuando cursor es null en settings', function () {
    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => null, 'updated_at' => now()]
    );

    Http::fake();

    // No debe lanzar excepción
    expect(fn () => dispatchJob())->not->toThrow(\Throwable::class);

    Http::assertNothingSent();
});

// ===========================================================================
// Con cursor válido → procesa archivos nuevos
// ===========================================================================

it('ProcessDropboxNotification procesa archivo nuevo e inserta en smd_synced_events', function () {
    $cursor  = 'cursor-job-'.uniqid();
    $payload = makeJobPayload();

    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => $cursor, 'updated_at' => now()]
    );

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries'  => [
                ['.tag' => 'file', 'name' => $payload['_id'].'.json', 'path_lower' => '/smd-events/2026-05-12/'.$payload['_id'].'.json'],
            ],
            'cursor'   => 'cursor-nuevo-'.uniqid(),
            'has_more' => false,
        ], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::response(
            json_encode($payload),
            200
        ),
    ]);

    dispatchJob();

    $this->assertDatabaseHas('smd_synced_events', [
        'external_id' => $payload['_id'],
    ]);
});

it('ProcessDropboxNotification actualiza el cursor en settings despues de procesar', function () {
    $cursor    = 'cursor-initial-'.uniqid();
    $newCursor = 'cursor-updated-'.uniqid();

    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => $cursor, 'updated_at' => now()]
    );

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries'  => [],
            'cursor'   => $newCursor,
            'has_more' => false,
        ], 200),
    ]);

    dispatchJob();

    $saved = DB::table('settings')
        ->where('key', 'dropbox_cursor')
        ->value('value');

    expect($saved)->toBe($newCursor);
    expect($saved)->not->toBe($cursor);
});

it('ProcessDropboxNotification hace skip de evento sin cambios (mismo hash)', function () {
    $cursor  = 'cursor-skip-'.uniqid();
    $payload = makeJobPayload();

    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => $cursor, 'updated_at' => now()]
    );

    // Pre-insertar el evento con el mismo payload
    DB::table('smd_synced_events')->insert([
        'external_id'  => $payload['_id'],
        'source_file'  => '/smd-events/2026-05-12/'.$payload['_id'].'.json',
        'raw_payload'  => json_encode($payload),
        'status'       => '',
        'processed_at' => now(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries'  => [
                ['.tag' => 'file', 'name' => $payload['_id'].'.json', 'path_lower' => '/smd-events/2026-05-12/'.$payload['_id'].'.json'],
            ],
            'cursor'   => 'cursor-next',
            'has_more' => false,
        ], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::response(
            json_encode($payload),
            200
        ),
    ]);

    // El job no debe llamar a IncomingAppointmentService
    $processedCalled = false;

    app()->bind(IncomingAppointmentService::class, function () use (&$processedCalled) {
        $mock = \Mockery::mock(IncomingAppointmentService::class);
        $mock->shouldNotReceive('processDropbox');
        $mock->shouldNotReceive('updateDropbox');
        $mock->shouldNotReceive('cancelDropbox');

        return $mock;
    });

    dispatchJob();

    expect($processedCalled)->toBeFalse();
});

it('ProcessDropboxNotification cancela evento con status CANCELED y marca archived_at', function () {
    $cursor  = 'cursor-cancel-'.uniqid();
    $payload = makeJobPayload(['status' => 'CANCELED', 'archived' => false]);

    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => $cursor, 'updated_at' => now()]
    );

    // Pre-insertar registro existente
    DB::table('smd_synced_events')->insert([
        'external_id'  => $payload['_id'],
        'source_file'  => '/smd-events/2026-05-12/'.$payload['_id'].'.json',
        'raw_payload'  => json_encode($payload),
        'status'       => '',
        'activity_id'  => null,
        'lead_id'      => null,
        'processed_at' => now(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries'  => [
                ['.tag' => 'file', 'name' => $payload['_id'].'.json', 'path_lower' => '/smd-events/2026-05-12/'.$payload['_id'].'.json'],
            ],
            'cursor'   => 'cursor-next',
            'has_more' => false,
        ], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::response(
            json_encode($payload),
            200
        ),
    ]);

    dispatchJob();

    $row = DB::table('smd_synced_events')
        ->where('external_id', $payload['_id'])
        ->first();

    expect($row)->not->toBeNull();
    expect($row->archived_at)->not->toBeNull();
    expect($row->status)->toBe('CANCELED');
});

it('ProcessDropboxNotification cancela evento con archived true aunque status este vacio', function () {
    $cursor  = 'cursor-arch-'.uniqid();
    $payload = makeJobPayload(['archived' => true, 'status' => '']);

    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => $cursor, 'updated_at' => now()]
    );

    DB::table('smd_synced_events')->insert([
        'external_id'  => $payload['_id'],
        'source_file'  => '/smd-events/2026-05-12/'.$payload['_id'].'.json',
        'raw_payload'  => json_encode($payload),
        'status'       => '',
        'activity_id'  => null,
        'lead_id'      => null,
        'processed_at' => now(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries'  => [
                ['.tag' => 'file', 'name' => $payload['_id'].'.json', 'path_lower' => '/smd-events/2026-05-12/'.$payload['_id'].'.json'],
            ],
            'cursor'   => 'cursor-arch-next',
            'has_more' => false,
        ], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::response(
            json_encode($payload),
            200
        ),
    ]);

    dispatchJob();

    $row = DB::table('smd_synced_events')
        ->where('external_id', $payload['_id'])
        ->first();

    expect($row->archived_at)->not->toBeNull();
});

it('ProcessDropboxNotification itera correctamente cuando has_more es true', function () {
    $cursor1 = 'cursor-page1-'.uniqid();
    $cursor2 = 'cursor-page2-'.uniqid();

    $payload1 = makeJobPayload();
    $payload2 = makeJobPayload();

    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => $cursor1, 'updated_at' => now()]
    );

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::sequence([
            // Primera pagina: has_more true
            Http::response([
                'entries'  => [
                    ['.tag' => 'file', 'name' => $payload1['_id'].'.json', 'path_lower' => '/smd-events/2026-05-12/'.$payload1['_id'].'.json'],
                ],
                'cursor'   => $cursor2,
                'has_more' => true,
            ], 200),
            // Segunda pagina: has_more false
            Http::response([
                'entries'  => [
                    ['.tag' => 'file', 'name' => $payload2['_id'].'.json', 'path_lower' => '/smd-events/2026-05-12/'.$payload2['_id'].'.json'],
                ],
                'cursor'   => 'cursor-final',
                'has_more' => false,
            ], 200),
        ]),
        'https://content.dropboxapi.com/2/files/download' => Http::sequence([
            Http::response(json_encode($payload1), 200),
            Http::response(json_encode($payload2), 200),
        ]),
    ]);

    dispatchJob();

    // Ambos eventos deben haberse procesado
    $this->assertDatabaseHas('smd_synced_events', ['external_id' => $payload1['_id']]);
    $this->assertDatabaseHas('smd_synced_events', ['external_id' => $payload2['_id']]);

    // El cursor final debe haberse guardado
    $savedCursor = DB::table('settings')
        ->where('key', 'dropbox_cursor')
        ->value('value');

    expect($savedCursor)->toBe('cursor-final');
});

it('ProcessDropboxNotification no cancela evento CANCELED sin registro previo en smd_synced_events', function () {
    $cursor  = 'cursor-noexist-'.uniqid();
    $payload = makeJobPayload(['status' => 'CANCELED']);
    $extId   = $payload['_id'];

    // Asegurarse de que NO existe
    DB::table('smd_synced_events')->where('external_id', $extId)->delete();

    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => $cursor, 'updated_at' => now()]
    );

    $cancelCalled = false;

    app()->bind(IncomingAppointmentService::class, function () use (&$cancelCalled) {
        $mock = \Mockery::mock(IncomingAppointmentService::class);
        $mock->shouldNotReceive('cancelDropbox');

        return $mock;
    });

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries'  => [
                ['.tag' => 'file', 'name' => $extId.'.json', 'path_lower' => '/smd-events/2026-05-12/'.$extId.'.json'],
            ],
            'cursor'   => 'cursor-x',
            'has_more' => false,
        ], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::response(
            json_encode($payload),
            200
        ),
    ]);

    dispatchJob();

    expect($cancelCalled)->toBeFalse();

    // No debe haberse insertado tampoco
    $this->assertDatabaseMissing('smd_synced_events', ['external_id' => $extId]);
});

it('ProcessDropboxNotification continua con el siguiente archivo cuando JSON descargado es invalido', function () {
    $cursor       = 'cursor-err-'.uniqid();
    $validPayload = makeJobPayload();

    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => $cursor, 'updated_at' => now()]
    );

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries'  => [
                ['.tag' => 'file', 'name' => 'corrupted.json', 'path_lower' => '/smd-events/2026-05-12/corrupted.json'],
                ['.tag' => 'file', 'name' => $validPayload['_id'].'.json', 'path_lower' => '/smd-events/2026-05-12/'.$validPayload['_id'].'.json'],
            ],
            'cursor'   => 'cursor-after-err',
            'has_more' => false,
        ], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::sequence([
            Http::response('NOT_VALID_JSON', 200),   // archivo corrupto
            Http::response(json_encode($validPayload), 200),  // archivo válido
        ]),
    ]);

    // No debe lanzar excepción aunque el primer archivo sea inválido
    expect(fn () => dispatchJob())->not->toThrow(\Throwable::class);

    // El segundo archivo válido sí debe haberse procesado
    $this->assertDatabaseHas('smd_synced_events', ['external_id' => $validPayload['_id']]);
});
