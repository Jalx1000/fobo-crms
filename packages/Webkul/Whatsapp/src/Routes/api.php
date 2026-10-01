<?php

use Illuminate\Support\Facades\Route;
use Webkul\Whatsapp\Http\Controllers\Api\AgentController;
use Webkul\Whatsapp\Http\Controllers\MediaController;
use Webkul\Whatsapp\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| WhatsApp webhook
|--------------------------------------------------------------------------
| A single public endpoint; the active gateway decides how to handshake,
| authenticate and parse. Outside the "web" group, so no CSRF/session.
|
| The optional {secret} segment is how providers that sign nothing (Kommo) are
| authenticated: knowing the URL *is* the credential. Providers that sign their
| payloads (Cloud API) use the bare path, which keeps existing Meta setups working.
*/

Route::prefix('api/v1/whatsapp')->group(function () {
    Route::get('webhook/{secret?}', [WebhookController::class, 'verify'])->name('whatsapp.webhook.verify');
    Route::post('webhook/{secret?}', [WebhookController::class, 'receive'])->name('whatsapp.webhook.receive');
});

/*
|--------------------------------------------------------------------------
| Outgoing media
|--------------------------------------------------------------------------
| The provider downloads attachments from here, so it cannot sit behind auth.
| The random 40-char filename is what keeps it private.
|
| It exists because the Content-Type has to match the type we declare to
| WhatsApp: served by the web server, a voice note recorded as audio/mp4 goes
| out as video/mp4, which WhatsApp will not accept for audio.
*/
Route::get('whatsapp/media/{file}', [MediaController::class, 'show'])
    ->where('file', '[A-Za-z0-9._-]+')
    ->name('whatsapp.media');

/*
|--------------------------------------------------------------------------
| Agent API (token / Sanctum)
|--------------------------------------------------------------------------
| Consumed by the external AI agent to read context and post replies. Auth is a
| user API key (Bearer), the same scheme as the rest of the Krayin REST API.
*/
Route::prefix('api/v1/whatsapp')
    // 'agent-api' is the generous, per-token bucket: a single customer turn is
    // several calls, and the reply POST must never be the one that gets 429'd.
    ->middleware(['api', 'throttle:agent-api', 'auth:sanctum'])
    ->group(function () {
        Route::get('conversations/{id}/messages', [AgentController::class, 'messages']);
        Route::post('conversations/{id}/messages', [AgentController::class, 'send']);

        // El agente suelta la conversación y se la pasa a una persona.
        Route::post('conversations/{id}/handoff', [AgentController::class, 'handoff']);
    });
