<?php

use Illuminate\Support\Facades\Route;
use Webkul\Whatsapp\Http\Controllers\Api\AgentController;
use Webkul\Whatsapp\Http\Controllers\Api\Mobile;
use Webkul\Whatsapp\Http\Controllers\Api\Mobile\MeController;
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

        // Botones y listas en formato estructurado. Mismo contrato que imprimir,
        // porque el agente es el mismo para los dos tenants.
        Route::post('conversations/{id}/interactive', [AgentController::class, 'sendInteractive']);

        // El agente suelta la conversación y se la pasa a una persona.
        Route::post('conversations/{id}/handoff', [AgentController::class, 'handoff']);
    });

/*
|--------------------------------------------------------------------------
| Mobile API (token / Sanctum)
|--------------------------------------------------------------------------
| La bandeja del CRM para la app mobile. El token es de una PERSONA (un
| asesor), no del bot: whatsapp_mobile_user lo pone en el guard "user" para
| que el permiso, "asignadas a mí" y el autor de cada acción salgan de él.
| Spec: tasks/spec-api-mobile-whatsapp.md
*/
Route::prefix('api/v1/mobile/whatsapp')
    ->middleware(['api', 'throttle:mobile-api', 'auth:sanctum', 'whatsapp_mobile_user'])
    ->name('whatsapp.mobile.')
    ->group(function () {
        // Fuera de whatsapp_access a propósito: la app lo llama al iniciar
        // sesión para saber si mostrar la bandeja o "no tenés acceso".
        Route::get('me', [MeController::class, 'show'])->name('me');

        Route::middleware('whatsapp_access')->group(function () {
            // Bandeja
            Route::get('conversations', [Mobile\ConversationController::class, 'index'])->name('conversations');
            Route::get('conversations/{id}/messages', [Mobile\ConversationController::class, 'messages'])->name('messages');
            Route::get('unread-count', [Mobile\ConversationController::class, 'unreadCount'])->name('unread-count');

            // Envío
            Route::post('conversations/{id}/messages', [Mobile\MessageController::class, 'text'])->name('send');
            Route::post('conversations/{id}/media', [Mobile\MessageController::class, 'media'])->name('send-media');
            Route::post('conversations/{id}/location', [Mobile\MessageController::class, 'location'])->name('send-location');
            Route::get('templates', [Mobile\MessageController::class, 'templates'])->name('templates');
            Route::post('conversations/{id}/template', [Mobile\MessageController::class, 'template'])->name('send-template');

            // Quién atiende
            Route::patch('conversations/{id}/agent', [Mobile\HandoffController::class, 'agent'])->name('agent');
            Route::post('conversations/{id}/handoff/claim', [Mobile\HandoffController::class, 'claim'])->name('handoff.claim');
            Route::post('conversations/{id}/handoff/resolve', [Mobile\HandoffController::class, 'resolve'])->name('handoff.resolve');
            Route::post('conversations/{id}/notes', [Mobile\HandoffController::class, 'note'])->name('notes');

            // Contexto CRM
            Route::post('conversations/{id}/link-person', [Mobile\CrmController::class, 'linkPerson'])->name('link-person');
            Route::get('persons/{id}', [Mobile\CrmController::class, 'person'])->name('person');
            Route::post('persons/{id}/leads', [Mobile\CrmController::class, 'storeLead'])->name('person.leads');
            Route::get('stages', [Mobile\CrmController::class, 'stages'])->name('stages');
            Route::get('cities', [Mobile\CrmController::class, 'cities'])->name('cities');
            Route::get('products', [Mobile\CrmController::class, 'products'])->name('products');
            Route::get('quick-replies', [Mobile\CrmController::class, 'quickReplies'])->name('quick-replies');
        });
    });
