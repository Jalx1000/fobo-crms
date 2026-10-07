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

    // Editar los productos de un pedido. PATCH y no PUT: cambia parte del pedido, no
    // lo reemplaza entero —el cliente, la ciudad y la etapa no se tocan—.
    Route::patch('pedido/{id}/productos', 'editarProductos')
        ->name('admin.distribuidor.pedido.productos');

    // La dirección que corresponde a un punto del mapa, para precargar el campo.
    Route::get('direccion', 'direccionDelPin')->name('admin.distribuidor.direccion');

    // Corregir el nombre y el teléfono. PATCH y no PUT: cambia parte de la ficha
    // —el domicilio tiene su propio editor con mapa— y no la reemplaza.
    Route::patch('cliente/{id}', 'actualizarCliente')->name('admin.distribuidor.cliente.update');

    // El domicilio del cliente, tomado con el GPS en la puerta.
    Route::post('cliente/{id}/ubicacion', 'guardarUbicacionCliente')
        ->name('admin.distribuidor.cliente.ubicacion');

    // Prender o apagar el recordatorio de recompra de un cliente.
    Route::patch('cliente/{id}/recordatorio', 'recordatorioDeCliente')
        ->name('admin.distribuidor.cliente.recordatorio');

    // Bidones sin pedido: pasar a juntar vacíos.
    Route::post('cliente/{id}/bidones', 'registrarBidones')
        ->name('admin.distribuidor.cliente.bidones');
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
        // La agenda de clientes. Antes era 'bidones' y listaba solo a los que
        // debian envases; ahora es la agenda entera y el saldo es una columna.
        Route::get('clientes', 'clientes')->name('admin.distribuidor.clientes');
        Route::get('repetir/{personId}', 'repetir')->name('admin.distribuidor.repetir');
    });
