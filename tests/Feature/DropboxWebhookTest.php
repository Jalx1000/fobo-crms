<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Webkul\Admin\Jobs\ProcessDropboxNotification;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(DatabaseTransactions::class);

// ---------------------------------------------------------------------------
// GET verify — handshake de Dropbox
// ---------------------------------------------------------------------------

it('responde al challenge de verificacion de Dropbox con text/plain', function () {
    $response = get('/api/webhooks/dropbox?challenge=abc123');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())->toBe('abc123');
});

it('responde con challenge vacio si no se envia parametro', function () {
    $response = get('/api/webhooks/dropbox');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())->toBe('');
});

// ---------------------------------------------------------------------------
// POST handle — firma HMAC
// ---------------------------------------------------------------------------

it('acepta POST con firma HMAC valida y despacha ProcessDropboxNotification', function () {
    Bus::fake();

    $appSecret = 'test-app-secret-'.uniqid();
    Config::set('smd.dropbox.app_secret', $appSecret);

    $body      = json_encode(['list_folder' => ['accounts' => ['abc']]]);
    $signature = hash_hmac('sha256', $body, $appSecret);

    // Enviar el body raw para que la firma calculada en el test coincida con
    // el body que recibe $request->getContent() en el controller.
    $this->call(
        'POST',
        '/api/webhooks/dropbox',
        [],
        [],
        [],
        [
            'HTTP_X-Dropbox-Signature' => $signature,
            'CONTENT_TYPE'             => 'application/json',
        ],
        $body
    )
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    Bus::assertDispatched(ProcessDropboxNotification::class);
});

it('rechaza POST con firma HMAC invalida con 403', function () {
    Bus::fake();

    $appSecret = 'test-app-secret-'.uniqid();
    Config::set('smd.dropbox.app_secret', $appSecret);

    $body = json_encode(['list_folder' => ['accounts' => ['abc']]]);

    post('/api/webhooks/dropbox', json_decode($body, true), [
        'X-Dropbox-Signature' => 'firma-incorrecta',
        'Content-Type'        => 'application/json',
    ])
        ->assertStatus(403)
        ->assertJson(['error' => 'Invalid signature']);

    Bus::assertNotDispatched(ProcessDropboxNotification::class);
});

it('rechaza POST sin header X-Dropbox-Signature con 403', function () {
    Bus::fake();

    $appSecret = 'test-app-secret-'.uniqid();
    Config::set('smd.dropbox.app_secret', $appSecret);

    post('/api/webhooks/dropbox', ['foo' => 'bar'])
        ->assertStatus(403)
        ->assertJson(['error' => 'Invalid signature']);

    Bus::assertNotDispatched(ProcessDropboxNotification::class);
});

it('rechaza POST cuando app_secret esta vacio en config con 403', function () {
    Bus::fake();

    Config::set('smd.dropbox.app_secret', '');

    $body      = json_encode(['list_folder' => ['accounts' => ['abc']]]);
    $signature = hash_hmac('sha256', $body, 'cualquier-secret');

    post('/api/webhooks/dropbox', json_decode($body, true), [
        'X-Dropbox-Signature' => $signature,
        'Content-Type'        => 'application/json',
    ])
        ->assertStatus(403)
        ->assertJson(['error' => 'Invalid signature']);

    Bus::assertNotDispatched(ProcessDropboxNotification::class);
});
