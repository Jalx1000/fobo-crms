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
        Route::patch('conversations/{id}/agent', 'toggleAgent')->name('agent');
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
