<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(DatabaseTransactions::class);

beforeEach(function () {
    \Illuminate\Support\Facades\Cache::forget('dropbox_access_token');

    config([
        'smd.dropbox.access_token'        => 'fake-access-token',
        'smd.dropbox.refresh_token'       => null,
        'smd.dropbox.app_key'             => null,
        'smd.dropbox.app_secret'          => null,
        'smd.dropbox.appointments_folder' => '/smd-events',
    ]);
});

// ---------------------------------------------------------------------------
// 1. Cursor guardado correctamente en od_settings (tabla settings con prefix od_)
// ---------------------------------------------------------------------------

it('smd:dropbox-init-cursor guarda el cursor en la tabla settings con key dropbox_cursor', function () {
    $fakeCursor = 'AAHgBqe123456789_fake_cursor_'.uniqid();

    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'fake-token',
        ], 200),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries'  => [],
            'cursor'   => $fakeCursor,
            'has_more' => false,
        ], 200),
    ]);

    $this->artisan('smd:dropbox-init-cursor')
        ->assertExitCode(0)
        ->expectsOutputToContain('Cursor guardado');

    $saved = DB::table('settings')
        ->where('key', 'dropbox_cursor')
        ->value('value');

    expect($saved)->toBe($fakeCursor);
});

// ---------------------------------------------------------------------------
// 2. Dropbox falla → comando retorna FAILURE
// ---------------------------------------------------------------------------

it('smd:dropbox-init-cursor retorna FAILURE cuando Dropbox no puede obtener el cursor', function () {
    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'fake-token',
        ], 200),
        // Fallo al listar la carpeta
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'error_summary' => 'invalid_access_token',
        ], 401),
    ]);

    $this->artisan('smd:dropbox-init-cursor')
        ->assertExitCode(1)  // Command::FAILURE = 1
        ->expectsOutputToContain('No se pudo obtener el cursor');
});

// ---------------------------------------------------------------------------
// 3. Cursor existente → se sobreescribe con el nuevo
// ---------------------------------------------------------------------------

it('smd:dropbox-init-cursor sobreescribe el cursor existente con el nuevo', function () {
    // Pre-insertar cursor viejo
    DB::table('settings')->updateOrInsert(
        ['key' => 'dropbox_cursor'],
        ['value' => 'cursor-viejo-123', 'updated_at' => now()]
    );

    $newCursor = 'cursor-nuevo-'.uniqid();

    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'fake-token',
        ], 200),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries'  => [],
            'cursor'   => $newCursor,
            'has_more' => false,
        ], 200),
    ]);

    $this->artisan('smd:dropbox-init-cursor')
        ->assertExitCode(0);

    $saved = DB::table('settings')
        ->where('key', 'dropbox_cursor')
        ->value('value');

    expect($saved)->toBe($newCursor);
    expect($saved)->not->toBe('cursor-viejo-123');
});

// ---------------------------------------------------------------------------
// 4. Cursor con has_more:true → itera hasta agotar la paginación
// ---------------------------------------------------------------------------

it('smd:dropbox-init-cursor itera list_folder/continue cuando has_more es true', function () {
    $cursorFinal = 'cursor-final-'.uniqid();

    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'fake-token',
        ], 200),
        // Primera llamada: has_more true
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries'  => [],
            'cursor'   => 'cursor-intermedio',
            'has_more' => true,
        ], 200),
        // Llamada de continue: has_more false
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries'  => [],
            'cursor'   => $cursorFinal,
            'has_more' => false,
        ], 200),
    ]);

    $this->artisan('smd:dropbox-init-cursor')
        ->assertExitCode(0);

    $saved = DB::table('settings')
        ->where('key', 'dropbox_cursor')
        ->value('value');

    expect($saved)->toBe($cursorFinal);
});
