<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Quote\QuoteController;

// Un ejecutivo solo toca sus cotizaciones (spec tablero-por-asesor, D13c).
Route::controller(QuoteController::class)->prefix('quotes')->middleware(\Webkul\Admin\Http\Middleware\CotizacionDelAsesor::class)->group(function () {
    Route::get('', 'index')->name('admin.quotes.index');

    Route::get('create/{lead_id?}', 'create')->name('admin.quotes.create');

    Route::post('create', 'store')->name('admin.quotes.store');

    Route::get('edit/{id?}', 'edit')->name('admin.quotes.edit');

    Route::put('edit/{id}', 'update')->name('admin.quotes.update');

    Route::get('print/{id?}', 'print')->name('admin.quotes.print');

    Route::post('{id}/enviar', 'send')->name('admin.quotes.send');

    Route::post('{id}/whatsapp', 'sendWhatsapp')->name('admin.quotes.whatsapp');

    Route::post('{id}/messenger', 'sendMessenger')->name('admin.quotes.messenger');

    Route::delete('{id}', 'destroy')->name('admin.quotes.delete');

    Route::get('search', 'search')->name('admin.quotes.search');

    Route::post('mass-destroy', 'massDestroy')->name('admin.quotes.mass_delete');
});
