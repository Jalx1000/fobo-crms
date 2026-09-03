<?php

use Illuminate\Support\Facades\Route;
use Webkul\Whatsapp\Http\Controllers\ChatController;
use Webkul\Whatsapp\Http\Controllers\InboxController;
use Webkul\Whatsapp\Http\Controllers\QuickReplyController;

/*
|--------------------------------------------------------------------------
| WhatsApp admin (authenticated) routes
|--------------------------------------------------------------------------
| Consumed by the inbox component inside the CRM. Registered under the admin
| path with the web + user middleware in the service provider.
*/

Route::controller(InboxController::class)
    ->prefix('whatsapp')
    ->name('admin.whatsapp.')
    ->group(function () {
        Route::get('thread', 'thread')->name('thread');
        Route::post('send', 'send')->name('send');
        Route::post('send-media', 'sendMedia')->name('send-media');
        Route::post('send-location', 'sendLocation')->name('send-location');

        // Archivos de la conversación. Detrás del login: el disco es privado.
        Route::get('media/{message}', 'media')->name('media');
        Route::patch('conversations/{id}/agent', 'toggleAgent')->name('agent');

        // Derivaciones. El agente las abre por su propia API; acá solo se las
        // toma y se las cierra, que es lo que un asesor hace desde el inbox.
        Route::get('conversations/{id}/handoffs', 'handoffHistory')->name('handoff.history');
        Route::post('conversations/{id}/handoff/claim', 'claimHandoff')->name('handoff.claim');
        Route::post('conversations/{id}/handoff/resolve', 'resolveHandoff')->name('handoff.resolve');
        Route::post('conversations/{id}/notes', 'storeNote')->name('notes.store');
    });

Route::controller(ChatController::class)
    ->prefix('whatsapp')
    ->name('admin.whatsapp.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('conversations', 'conversations')->name('conversations');
        Route::post('conversations/{id}/link-person', 'linkPerson')->name('link-person');
        Route::get('persons/{id}', 'person')->name('person');
        Route::post('persons/{id}/leads', 'storeLead')->name('person.leads');
        Route::get('products', 'products')->name('products');
        Route::get('stages', 'stages')->name('stages');
    });

Route::controller(QuickReplyController::class)
    ->prefix('whatsapp/quick-replies')
    ->name('admin.whatsapp.quick-replies.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::put('{id}', 'update')->name('update');
        Route::delete('{id}', 'destroy')->name('destroy');
    });
