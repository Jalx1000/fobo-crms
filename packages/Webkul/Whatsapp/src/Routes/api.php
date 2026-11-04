<?php

use Illuminate\Support\Facades\Route;
use Webkul\Whatsapp\Http\Controllers\Api\AgentController;
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
| Entrada propia de Cloud API, fijada a ese canal sea cual sea el activo.
|
| En el modo mixto (Kommo recibe, Cloud API envía) Meta no tenía a dónde
| mandar los estados de entrega de lo que sale por Cloud API: el webhook de
| arriba lo atiende Kommo. Esta URL va en la app de Meta del número que envía.
*/
Route::prefix('api/v1/webhooks')->group(function () {
    Route::get('cloud-api', [WebhookController::class, 'verify'])
        ->defaults('channel', 'cloud_api')
        ->name('whatsapp.cloud-api.webhook.verify');

    Route::post('cloud-api', [WebhookController::class, 'receive'])
        ->defaults('channel', 'cloud_api')
        ->name('whatsapp.cloud-api.webhook.receive');
});

/*
|--------------------------------------------------------------------------
| Agent API (token / Sanctum)
|--------------------------------------------------------------------------
| Consumed by the external AI agent to read context and post replies. Auth is a
| user API key (Bearer), the same scheme as the rest of the Krayin REST API.
*/
Route::prefix('api/v1/whatsapp')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::get('conversations/{id}/messages', [AgentController::class, 'messages']);
        Route::post('conversations/{id}/messages', [AgentController::class, 'send']);
        Route::post('conversations/{id}/interactive', [AgentController::class, 'sendInteractive']);
        Route::post('conversations/{id}/handoff', [AgentController::class, 'handoff']);
        Route::post('conversations/{id}/etapa', [AgentController::class, 'etapa']);
    });
