<?php

use Illuminate\Support\Facades\Route;
use Webkul\Distribuidor\Http\Controllers\PuntoController;

Route::controller(PuntoController::class)->prefix('distribuidor')->group(function () {
    Route::get('', 'index')->name('admin.distribuidor.index');

    Route::get('productos', 'productos')->name('admin.distribuidor.productos');
    Route::get('pedidos', 'pedidos')->name('admin.distribuidor.pedidos');

    Route::get('contacto', 'buscarContacto')->name('admin.distribuidor.contacto.buscar');
    Route::post('contacto', 'guardarContacto')->name('admin.distribuidor.contacto.store');

    Route::post('pedido', 'guardarPedido')->name('admin.distribuidor.pedido.store');
    Route::patch('pedido/{id}/etapa', 'avanzar')->name('admin.distribuidor.pedido.etapa');
    Route::post('pedido/{id}/cobro', 'cobrar')->name('admin.distribuidor.pedido.cobro');
});

/*
 * El chat del cliente, a un toque del pedido. Fuera del grupo de arriba porque
 * devuelve una página y no JSON.
 */
Route::get('distribuidor/whatsapp/{id}', [\Webkul\Distribuidor\Http\Controllers\PuntoController::class, 'whatsapp'])
    ->name('admin.distribuidor.whatsapp');

Route::controller(\Webkul\Distribuidor\Http\Controllers\PuntoController::class)
    ->prefix('distribuidor')->group(function () {
        Route::get('recientes', 'recientes')->name('admin.distribuidor.recientes');
        Route::get('caja', 'caja')->name('admin.distribuidor.caja');
        Route::get('repetir/{personId}', 'repetir')->name('admin.distribuidor.repetir');
    });
