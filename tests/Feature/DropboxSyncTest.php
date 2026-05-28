<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\DropboxService;
use Webkul\Admin\Services\IncomingAppointmentService;
use Webkul\Admin\Services\SmdStatusMapper;

uses(DatabaseTransactions::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeSmdJson(array $overrides = []): array
{
    $id = uniqid('ev_', true);

    return array_merge([
        '_id'         => $id,
        'archived'    => false,
        'status'      => '',
        'startDate'   => '2026-05-12T15:45:00.000Z',
        'endDate'     => '2026-05-12T16:15:00.000Z',
        'summary'     => 'Consulta Test',
        'owner'       => '69bd9a6a7549b10008e0ac6b',
        'attendances' => [
            [
                '_id'      => 'pat-'.uniqid(),
                'name'     => 'Paciente',
                'type'     => 'patient',
                'lastName' => 'Prueba',
                'phone'    => '7'.rand(1000000, 9999999),
            ],
            [
                '_id'      => 'doc-'.uniqid(),
                'name'     => 'Dr. Sync',
                'type'     => 'physician',
                'lastName' => 'Test',
                'phone'    => '',
            ],
        ],
    ], $overrides);
}

/**
 * Construye las respuestas Http::fake() para simular un ciclo completo de Dropbox:
 * 1. list_folder (token OAuth si aplica)
 * 2. list_folder/files para la fecha → devuelve $files
 * 3. content.dropboxapi.com/download → devuelve $jsonBody para cada file
 */
function fakeDropboxForDate(string $date, array $files, array $jsonBodies): void
{
    $downloadResponses = [];

    foreach ($jsonBodies as $body) {
        $downloadResponses[] = Http::response(json_encode($body), 200);
    }

    Http::fake([
        // Refresh token (si se intenta renovar)
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'fake-token-'.uniqid(),
        ], 200),

        // list_folder para la fecha
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries'  => $files,
            'cursor'   => 'cursor-'.uniqid(),
            'has_more' => false,
        ], 200),

        // Descarga de cada JSON
        'https://content.dropboxapi.com/2/files/download' => Http::sequence($downloadResponses),
    ]);
}

beforeEach(function () {
    // Limpiar caché del token entre tests
    \Illuminate\Support\Facades\Cache::forget('dropbox_access_token');

    // DB_PREFIX=od_ aplica automáticamente, no agregar manualmente el prefijo
    $stageId = DB::table('lead_pipeline_stages')->value('id') ?? 1;

    config([
        'smd.dropbox.access_token'        => 'fake-access-token',
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

// ---------------------------------------------------------------------------
// 1. Evento nuevo → processDropbox + insert en smd_synced_events
// ---------------------------------------------------------------------------

it('comando smd:sync-dropbox procesa evento nuevo y lo inserta en smd_synced_events', function () {
    $json      = makeSmdJson();
    $externalId = $json['_id'];

    $filePath = '/smd-events/2026-05-12/'.$externalId.'.json';
    $files    = [['name' => $externalId.'.json', 'path_lower' => $filePath]];

    fakeDropboxForDate('2026-05-12', $files, [$json]);

    $this->artisan('smd:sync-dropbox', ['--date' => '2026-05-12'])
        ->assertExitCode(0);

    $this->assertDatabaseHas('smd_synced_events', [
        'external_id' => $externalId,
        'source_file' => $filePath,
    ]);
});

// ---------------------------------------------------------------------------
// 2. Evento existente sin cambios → skip (mismo hash)
// ---------------------------------------------------------------------------

it('comando smd:sync-dropbox hace skip cuando el hash del payload no cambia', function () {
    $json       = makeSmdJson();
    $externalId = $json['_id'];
    $rawPayload = json_encode($json);

    // Pre-insertar como ya procesado
    DB::table('smd_synced_events')->insert([
        'external_id'  => $externalId,
        'source_file'  => '/smd-events/2026-05-12/'.$externalId.'.json',
        'raw_payload'  => $rawPayload,
        'status'       => '',
        'processed_at' => now(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $files = [['name' => $externalId.'.json', 'path_lower' => '/smd-events/2026-05-12/'.$externalId.'.json']];
    fakeDropboxForDate('2026-05-12', $files, [$json]);

    // Espiar IncomingAppointmentService para verificar que NO se llama processDropbox
    $called = false;
    $this->app->bind(IncomingAppointmentService::class, function () use (&$called) {
        $mock = \Mockery::mock(IncomingAppointmentService::class);
        $mock->shouldNotReceive('processDropbox');
        $mock->shouldNotReceive('updateDropbox');
        $mock->shouldNotReceive('cancelDropbox');

        return $mock;
    });

    $this->artisan('smd:sync-dropbox', ['--date' => '2026-05-12'])
        ->assertExitCode(0);
});

// ---------------------------------------------------------------------------
// 3. Evento existente con cambios → updateDropbox
// ---------------------------------------------------------------------------

it('comando smd:sync-dropbox llama updateDropbox cuando el payload cambia', function () {
    $json       = makeSmdJson();
    $externalId = $json['_id'];

    // Insertar con payload diferente (hash distinto)
    DB::table('smd_synced_events')->insert([
        'external_id'  => $externalId,
        'source_file'  => '/smd-events/2026-05-12/'.$externalId.'.json',
        'raw_payload'  => json_encode(['_id' => $externalId, 'summary' => 'ANTERIOR']),
        'status'       => '',
        'activity_id'  => null,
        'lead_id'      => null,
        'processed_at' => now(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $files = [['name' => $externalId.'.json', 'path_lower' => '/smd-events/2026-05-12/'.$externalId.'.json']];
    fakeDropboxForDate('2026-05-12', $files, [$json]);

    $updateCalled = false;
    $this->app->bind(IncomingAppointmentService::class, function () use (&$updateCalled) {
        $mock = \Mockery::mock(IncomingAppointmentService::class);
        $mock->shouldReceive('updateDropbox')
            ->once()
            ->andReturnUsing(function () use (&$updateCalled) {
                $updateCalled = true;

                return ['lead_id' => 1, 'activity_id' => 1, 'action' => 'updated'];
            });

        return $mock;
    });

    $this->artisan('smd:sync-dropbox', ['--date' => '2026-05-12'])
        ->assertExitCode(0);

    expect($updateCalled)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 4. Evento con status CANCELED → cancelDropbox
// ---------------------------------------------------------------------------

it('comando smd:sync-dropbox llama cancelDropbox cuando status es CANCELED y existe registro previo', function () {
    $json       = makeSmdJson(['status' => 'CANCELED', 'archived' => false]);
    $externalId = $json['_id'];

    DB::table('smd_synced_events')->insert([
        'external_id'  => $externalId,
        'source_file'  => '/smd-events/2026-05-12/'.$externalId.'.json',
        'raw_payload'  => json_encode($json),
        'status'       => '',
        'activity_id'  => null,
        'lead_id'      => null,
        'processed_at' => now(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $files = [['name' => $externalId.'.json', 'path_lower' => '/smd-events/2026-05-12/'.$externalId.'.json']];
    fakeDropboxForDate('2026-05-12', $files, [$json]);

    $cancelCalled = false;
    $this->app->bind(IncomingAppointmentService::class, function () use (&$cancelCalled) {
        $mock = \Mockery::mock(IncomingAppointmentService::class);
        $mock->shouldReceive('cancelDropbox')
            ->once()
            ->andReturnUsing(function () use (&$cancelCalled) {
                $cancelCalled = true;

                return ['lead_id' => 1, 'activity_id' => null, 'action' => 'cancelled'];
            });

        return $mock;
    });

    $this->artisan('smd:sync-dropbox', ['--date' => '2026-05-12'])
        ->assertExitCode(0);

    expect($cancelCalled)->toBeTrue();

    // El registro debe haber sido marcado con archived_at
    $row = DB::table('smd_synced_events')->where('external_id', $externalId)->first();
    expect($row->archived_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 5. Evento con archived: true → cancelDropbox
// ---------------------------------------------------------------------------

it('comando smd:sync-dropbox llama cancelDropbox cuando archived es true', function () {
    $json       = makeSmdJson(['archived' => true, 'status' => '']);
    $externalId = $json['_id'];

    DB::table('smd_synced_events')->insert([
        'external_id'  => $externalId,
        'source_file'  => '/smd-events/2026-05-12/'.$externalId.'.json',
        'raw_payload'  => json_encode($json),
        'status'       => '',
        'activity_id'  => null,
        'lead_id'      => null,
        'processed_at' => now(),
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $files = [['name' => $externalId.'.json', 'path_lower' => '/smd-events/2026-05-12/'.$externalId.'.json']];
    fakeDropboxForDate('2026-05-12', $files, [$json]);

    $cancelCalled = false;
    $this->app->bind(IncomingAppointmentService::class, function () use (&$cancelCalled) {
        $mock = \Mockery::mock(IncomingAppointmentService::class);
        $mock->shouldReceive('cancelDropbox')
            ->once()
            ->andReturnUsing(function () use (&$cancelCalled) {
                $cancelCalled = true;

                return ['lead_id' => null, 'activity_id' => null, 'action' => 'cancelled'];
            });

        return $mock;
    });

    $this->artisan('smd:sync-dropbox', ['--date' => '2026-05-12'])
        ->assertExitCode(0);

    expect($cancelCalled)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 6. Evento archived:true SIN registro previo → cancelDropbox NO se llama
// ---------------------------------------------------------------------------

it('comando smd:sync-dropbox NO llama cancelDropbox cuando archived es true pero no hay registro previo', function () {
    $json       = makeSmdJson(['archived' => true, 'status' => '']);
    $externalId = $json['_id'];

    // Asegurar que NO existe en smd_synced_events
    DB::table('smd_synced_events')->where('external_id', $externalId)->delete();

    $files = [['name' => $externalId.'.json', 'path_lower' => '/smd-events/2026-05-12/'.$externalId.'.json']];
    fakeDropboxForDate('2026-05-12', $files, [$json]);

    $cancelCalled = false;
    $this->app->bind(IncomingAppointmentService::class, function () use (&$cancelCalled) {
        $mock = \Mockery::mock(IncomingAppointmentService::class);
        $mock->shouldNotReceive('cancelDropbox');

        return $mock;
    });

    $this->artisan('smd:sync-dropbox', ['--date' => '2026-05-12'])
        ->assertExitCode(0);

    expect($cancelCalled)->toBeFalse();
});

// ---------------------------------------------------------------------------
// 7. Carpeta de fecha inexistente (Dropbox 409) → 0 archivos, sin error
// ---------------------------------------------------------------------------

it('comando smd:sync-dropbox maneja 409 de Dropbox sin error', function () {
    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'fake-token',
        ], 200),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'error_summary' => 'path/not_found/',
        ], 409),
    ]);

    $this->artisan('smd:sync-dropbox', ['--date' => '2099-01-01'])
        ->assertExitCode(0)
        ->expectsOutputToContain('0 archivos');
});

// ---------------------------------------------------------------------------
// 8. Token expirado (401) → se limpia cache y reintenta
// ---------------------------------------------------------------------------

it('DropboxService renueva token cuando Dropbox responde 401', function () {
    // Primera llamada devuelve 401, segunda (con token nuevo) devuelve lista vacía
    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'nuevo-token-'.uniqid(),
        ], 200),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::sequence([
            Http::response([], 401),           // primer intento → expirado
            Http::response([                    // segundo intento → ok
                'entries'  => [],
                'cursor'   => 'cursor-test',
                'has_more' => false,
            ], 200),
        ]),
    ]);

    config([
        'smd.dropbox.refresh_token' => 'fake-refresh-token',
        'smd.dropbox.app_key'       => 'fake-app-key',
        'smd.dropbox.app_secret'    => 'fake-app-secret',
    ]);

    \Illuminate\Support\Facades\Cache::forget('dropbox_access_token');

    $dropbox = app(DropboxService::class);
    $files   = $dropbox->listFilesForDate('2026-05-12');

    expect($files)->toBe([]);
    // Si llegamos aquí sin excepción, el retry funcionó
});

// ---------------------------------------------------------------------------
// 9. JSON descargado inválido (null) → $errors++, continúa con el siguiente
// ---------------------------------------------------------------------------

it('comando smd:sync-dropbox incrementa errores cuando el JSON descargado es null', function () {
    $externalId = 'ev-null-'.uniqid();
    $files      = [['name' => $externalId.'.json', 'path_lower' => '/smd-events/2026-05-12/'.$externalId.'.json']];

    Http::fake([
        'https://api.dropboxapi.com/oauth2/token'        => Http::response(['access_token' => 'fake'], 200),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => $files, 'cursor' => 'c', 'has_more' => false], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::response('not-json', 200),
    ]);

    $this->artisan('smd:sync-dropbox', ['--date' => '2026-05-12'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Errores: 1');
});

// ---------------------------------------------------------------------------
// 10. JSON sin campo _id → $errors++, continúa
// ---------------------------------------------------------------------------

it('comando smd:sync-dropbox incrementa errores cuando el JSON no tiene _id', function () {
    $files = [['name' => 'sin-id.json', 'path_lower' => '/smd-events/2026-05-12/sin-id.json']];

    Http::fake([
        'https://api.dropboxapi.com/oauth2/token'        => Http::response(['access_token' => 'fake'], 200),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => $files, 'cursor' => 'c', 'has_more' => false], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::response(json_encode(['summary' => 'Sin ID']), 200),
    ]);

    $this->artisan('smd:sync-dropbox', ['--date' => '2026-05-12'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Errores: 1');
});
