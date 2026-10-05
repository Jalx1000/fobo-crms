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

/*
 * whatsapp_scope: la conversación tiene que ser de una ciudad del usuario.
 *
 * Va sobre todo el grupo y no ruta por ruta porque acá TODO recibe una
 * conversación —por {id} o por conversation_id— y olvidarse en una sola ruta
 * reabre el agujero entero.
 */
Route::controller(InboxController::class)
    ->prefix('whatsapp')
    ->name('admin.whatsapp.')
    ->middleware('whatsapp_scope')
    ->group(function () {
        Route::get('thread', 'thread')->name('thread');
        Route::post('send', 'send')->name('send');
        Route::get('templates', 'templates')->name('templates');
        Route::post('send-template', 'sendTemplate')->name('send-template');
        Route::post('send-media', 'sendMedia')->name('send-media');
        Route::post('send-location', 'sendLocation')->name('send-location');
        Route::patch('conversations/{id}/agent', 'toggleAgent')->name('agent');
        Route::post('conversations/{id}/notes', 'storeNote')->name('notes.store');
        Route::post('conversations/{id}/handoff', 'requestHandoff')->name('handoff.request');
        Route::post('conversations/{id}/handoff/claim', 'claimHandoff')->name('handoff.claim');
        Route::post('conversations/{id}/handoff/resolve', 'resolveHandoff')->name('handoff.resolve');
    });

Route::controller(ChatController::class)
    ->prefix('whatsapp')
    ->name('admin.whatsapp.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('conversations', 'conversations')->name('conversations');
        // Sólo esta ruta del grupo recibe una conversación: en persons/{id} el
        // {id} es una persona, y el middleware lo leería como conversación.
        Route::post('conversations/{id}/link-person', 'linkPerson')
            ->middleware('whatsapp_scope')->name('link-person');
        Route::get('persons/{id}', 'person')->name('person');
        Route::post('persons/{id}/leads', 'storeLead')->name('person.leads');
        Route::get('products', 'products')->name('products');
        Route::get('stages', 'stages')->name('stages');
        Route::get('cities', 'cities')->name('cities');
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
