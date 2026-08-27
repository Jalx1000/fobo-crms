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

        Route::get('conversations/{id}/historial', [AgentController::class, 'historial']);
        Route::post('conversations/{id}/telefono', [AgentController::class, 'telefono']);

        // Cerrar el handoff lo hace el CRM cuando el asesor termina, no el
        // agente. Abrirlo vive en el módulo Inmobiliaria, porque necesita
        // resolver el asesor titular a partir del código del inmueble.
        Route::delete('conversations/{id}/handoff', [AgentController::class, 'cerrarHandoff']);
    });
