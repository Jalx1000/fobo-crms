<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\DropboxService;

uses(DatabaseTransactions::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function freshDropboxService(): DropboxService
{
    Cache::forget('dropbox_access_token');

    return app(DropboxService::class);
}

beforeEach(function () {
    Cache::forget('dropbox_access_token');

    config([
        'smd.dropbox.access_token'        => 'static-fallback-token',
        'smd.dropbox.refresh_token'       => null,
        'smd.dropbox.app_key'             => null,
        'smd.dropbox.app_secret'          => null,
        'smd.dropbox.appointments_folder' => '/smd-events',
    ]);
});

// ===========================================================================
// token() — lógica de caché y refresh
// ===========================================================================

it('token usa access_token del config cuando no hay credenciales de refresh', function () {
    // Sin refresh_token/app_key/app_secret → debe usar el fallback del config
    // sin hacer ninguna llamada HTTP
    Http::fake(); // no debería llamar a ninguna URL

    $dropbox = freshDropboxService();

    // listFilesForDate usa el token internamente; si llegamos a la llamada a
    // list_folder con el token correcto, el test pasa
    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries'  => [],
            'cursor'   => 'cur-1',
            'has_more' => false,
        ], 200),
    ]);

    Cache::forget('dropbox_access_token');
    $dropbox = freshDropboxService();
    $files   = $dropbox->listFilesForDate('2026-05-12');

    expect($files)->toBe([]);

    // No debe haberse llamado al endpoint de OAuth
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'oauth2/token'));
});

it('token obtiene access_token via refresh cuando hay credenciales configuradas', function () {
    $freshToken = 'refreshed-token-'.uniqid();

    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => $freshToken,
        ], 200),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries'  => [],
            'cursor'   => 'cur-x',
            'has_more' => false,
        ], 200),
    ]);

    config([
        'smd.dropbox.refresh_token' => 'rtoken-abc',
        'smd.dropbox.app_key'       => 'appkey-abc',
        'smd.dropbox.app_secret'    => 'appsecret-abc',
    ]);

    $dropbox = freshDropboxService();
    $dropbox->listFilesForDate('2026-05-12');

    Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2/token')
        && $req->data()['grant_type'] === 'refresh_token'
        && $req->data()['refresh_token'] === 'rtoken-abc'
    );
});

it('token queda cacheado y no vuelve a llamar oauth2/token en la segunda invocacion', function () {
    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::sequence([
            Http::response(['access_token' => 'cached-token'], 200),
            // Si se llama una segunda vez, devolvería esto — pero no debería ocurrir
            Http::response(['access_token' => 'second-call-error'], 200),
        ]),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries'  => [],
            'cursor'   => 'cur-2',
            'has_more' => false,
        ], 200),
    ]);

    config([
        'smd.dropbox.refresh_token' => 'rtoken-cache-test',
        'smd.dropbox.app_key'       => 'appkey-cache',
        'smd.dropbox.app_secret'    => 'appsecret-cache',
    ]);

    $dropbox = freshDropboxService();

    // Primera llamada → debe refrescar token una sola vez
    $dropbox->listFilesForDate('2026-05-12');
    // Segunda llamada → token en cache, no debe llamar oauth2/token de nuevo
    $dropbox->listFilesForDate('2026-05-13');

    // Solo debe haber exactamente 1 llamada a oauth2/token
    Http::assertSentCount(3); // 1 oauth2/token + 2 list_folder
});

it('token cae al fallback access_token cuando el refresh falla', function () {
    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'error' => 'invalid_grant',
        ], 400),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries'  => [],
            'cursor'   => 'cur-3',
            'has_more' => false,
        ], 200),
    ]);

    config([
        'smd.dropbox.access_token'  => 'fallback-static-token',
        'smd.dropbox.refresh_token' => 'bad-refresh-token',
        'smd.dropbox.app_key'       => 'appkey',
        'smd.dropbox.app_secret'    => 'appsecret',
    ]);

    $dropbox = freshDropboxService();

    // No debe lanzar excepción — debe usar el fallback
    $files = $dropbox->listFilesForDate('2026-05-12');

    expect($files)->toBe([]);
});

// ===========================================================================
// listFilesForDate()
// ===========================================================================

it('listFilesForDate devuelve solo archivos json de la respuesta', function () {
    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries'  => [
                ['name' => 'evento1.json', 'path_lower' => '/smd-events/2026-05-12/evento1.json', '.tag' => 'file'],
                ['name' => 'imagen.png',   'path_lower' => '/smd-events/2026-05-12/imagen.png',   '.tag' => 'file'],
                ['name' => 'evento2.json', 'path_lower' => '/smd-events/2026-05-12/evento2.json', '.tag' => 'file'],
                ['name' => 'README.txt',   'path_lower' => '/smd-events/2026-05-12/readme.txt',   '.tag' => 'file'],
            ],
            'cursor'   => 'cur-ok',
            'has_more' => false,
        ], 200),
    ]);

    $dropbox = freshDropboxService();
    $files   = $dropbox->listFilesForDate('2026-05-12');

    expect($files)->toHaveCount(2);
    expect($files[0]['name'])->toBe('evento1.json');
    expect($files[1]['name'])->toBe('evento2.json');
});

it('listFilesForDate devuelve array vacio cuando Dropbox responde 409 (carpeta no existe)', function () {
    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'error_summary' => 'path/not_found/.',
        ], 409),
    ]);

    $dropbox = freshDropboxService();
    $files   = $dropbox->listFilesForDate('2099-12-31');

    expect($files)->toBe([]);
});

it('listFilesForDate devuelve array vacio cuando Dropbox responde error generico 500', function () {
    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
            'error_summary' => 'internal_server_error/.',
        ], 500),
    ]);

    $dropbox = freshDropboxService();
    $files   = $dropbox->listFilesForDate('2026-05-12');

    expect($files)->toBe([]);
});

it('listFilesForDate renueva token y reintenta cuando Dropbox responde 401 y el segundo intento es exitoso', function () {
    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'token-renovado-'.uniqid(),
        ], 200),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::sequence([
            Http::response([], 401),  // primer intento → expirado
            Http::response([          // segundo intento con token nuevo → ok
                'entries'  => [
                    ['name' => 'cita.json', 'path_lower' => '/smd-events/2026-05-12/cita.json', '.tag' => 'file'],
                ],
                'cursor'   => 'cur-renewed',
                'has_more' => false,
            ], 200),
        ]),
    ]);

    config([
        'smd.dropbox.refresh_token' => 'valid-refresh',
        'smd.dropbox.app_key'       => 'ak',
        'smd.dropbox.app_secret'    => 'as',
    ]);

    $dropbox = freshDropboxService();
    $files   = $dropbox->listFilesForDate('2026-05-12');

    expect($files)->toHaveCount(1);
    expect($files[0]['name'])->toBe('cita.json');
});

it('listFilesForDate devuelve array vacio cuando ambos intentos (401 + 401) fallan', function () {
    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'nuevo-token',
        ], 200),
        'https://api.dropboxapi.com/2/files/list_folder' => Http::sequence([
            Http::response([], 401),  // primer intento
            Http::response([], 401),  // segundo intento — también falla
        ]),
    ]);

    config([
        'smd.dropbox.refresh_token' => 'valid-refresh',
        'smd.dropbox.app_key'       => 'ak',
        'smd.dropbox.app_secret'    => 'as',
    ]);

    $dropbox = freshDropboxService();
    $files   = $dropbox->listFilesForDate('2026-05-12');

    expect($files)->toBe([]);
});

// ===========================================================================
// downloadJson()
// ===========================================================================

it('downloadJson devuelve array parseado cuando la descarga es exitosa', function () {
    $payload = [
        '_id'       => 'ev-dl-test-123',
        'summary'   => 'Consulta de prueba',
        'startDate' => '2026-05-12T15:45:00.000Z',
        'status'    => '',
    ];

    Http::fake([
        'https://content.dropboxapi.com/2/files/download' => Http::response(
            json_encode($payload),
            200
        ),
    ]);

    $dropbox = freshDropboxService();
    $result  = $dropbox->downloadJson('/smd-events/2026-05-12/ev-dl-test-123.json');

    expect($result)->toBeArray();
    expect($result['_id'])->toBe('ev-dl-test-123');
    expect($result['summary'])->toBe('Consulta de prueba');
});

it('downloadJson devuelve null cuando la respuesta no es 200', function () {
    Http::fake([
        'https://content.dropboxapi.com/2/files/download' => Http::response([
            'error_summary' => 'path/not_found/.',
        ], 404),
    ]);

    $dropbox = freshDropboxService();
    $result  = $dropbox->downloadJson('/smd-events/2026-05-12/no-existe.json');

    expect($result)->toBeNull();
});

it('downloadJson envia body vacio para evitar error 400 body not empty', function () {
    Http::fake([
        'https://content.dropboxapi.com/2/files/download' => Http::response(
            json_encode(['_id' => 'test']),
            200
        ),
    ]);

    $dropbox = freshDropboxService();
    $dropbox->downloadJson('/smd-events/test.json');

    Http::assertSent(function ($request) {
        // Verificar que la URL es la correcta y que no lleva body con datos
        return str_contains($request->url(), 'content.dropboxapi.com/2/files/download')
            && $request->header('Dropbox-API-Arg') !== null;
    });
});

it('downloadJson renueva token y reintenta cuando recibe 401', function () {
    $payload = ['_id' => 'ev-retry-401', 'status' => ''];

    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'token-nuevo-dl',
        ], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::sequence([
            Http::response([], 401),  // primer intento → expirado
            Http::response(json_encode($payload), 200),  // segundo intento → ok
        ]),
    ]);

    config([
        'smd.dropbox.refresh_token' => 'rtoken-dl',
        'smd.dropbox.app_key'       => 'ak-dl',
        'smd.dropbox.app_secret'    => 'as-dl',
    ]);

    $dropbox = freshDropboxService();
    $result  = $dropbox->downloadJson('/smd-events/test.json');

    expect($result)->toBeArray();
    expect($result['_id'])->toBe('ev-retry-401');
});

it('downloadJson devuelve null cuando ambos intentos de descarga fallan con 401', function () {
    Http::fake([
        'https://api.dropboxapi.com/oauth2/token' => Http::response([
            'access_token' => 'token-inutil',
        ], 200),
        'https://content.dropboxapi.com/2/files/download' => Http::sequence([
            Http::response([], 401),
            Http::response([], 401),
        ]),
    ]);

    config([
        'smd.dropbox.refresh_token' => 'rtoken',
        'smd.dropbox.app_key'       => 'ak',
        'smd.dropbox.app_secret'    => 'as',
    ]);

    $dropbox = freshDropboxService();
    $result  = $dropbox->downloadJson('/smd-events/test.json');

    expect($result)->toBeNull();
});

it('downloadJson devuelve null cuando el body no es JSON valido', function () {
    Http::fake([
        'https://content.dropboxapi.com/2/files/download' => Http::response('not-json-at-all', 200),
    ]);

    $dropbox = freshDropboxService();
    $result  = $dropbox->downloadJson('/smd-events/corrupted.json');

    expect($result)->toBeNull();
});

// ===========================================================================
// listChanges()
// ===========================================================================

it('listChanges devuelve entries cursor y has_more correctamente', function () {
    $cursor = 'cursor-test-'.uniqid();

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries'  => [
                ['.tag' => 'file', 'name' => 'evento.json', 'path_lower' => '/smd-events/2026-05-12/evento.json'],
                ['.tag' => 'file', 'name' => 'otro.txt',    'path_lower' => '/smd-events/2026-05-12/otro.txt'],
                ['.tag' => 'deleted', 'name' => 'borrado.json'],
            ],
            'cursor'   => 'cursor-nuevo',
            'has_more' => false,
        ], 200),
    ]);

    $dropbox = freshDropboxService();
    $result  = $dropbox->listChanges($cursor);

    // Solo archivos .json con .tag === 'file'
    expect($result['entries'])->toHaveCount(1);
    expect($result['entries'][0]['name'])->toBe('evento.json');
    expect($result['cursor'])->toBe('cursor-nuevo');
    expect($result['has_more'])->toBeFalse();
});

it('listChanges devuelve entries vacio y cursor original cuando Dropbox responde 500', function () {
    $cursor = 'cursor-error-test';

    Http::fake([
        'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'error_summary' => 'internal/.',
        ], 500),
    ]);

    $dropbox = freshDropboxService();
    $result  = $dropbox->listChanges($cursor);

    expect($result['entries'])->toBe([]);
    expect($result['cursor'])->toBe($cursor);
    expect($result['has_more'])->toBeFalse();
});
