<?php

use Illuminate\Support\Facades\Route;
use Webkul\Admin\Http\Controllers\Products\ActivityController;
use Webkul\Admin\Http\Controllers\Products\ProductController;
use Webkul\Admin\Http\Controllers\Products\ProductMediaController;
use Webkul\Admin\Http\Controllers\Products\ProductoInboxController;
use Webkul\Admin\Http\Controllers\Products\TagController;

Route::group(['middleware' => ['user']], function () {
    Route::controller(ProductController::class)->prefix('products')->group(function () {
        Route::get('', 'index')->name('admin.products.index');

        Route::get('create', 'create')->name('admin.products.create');

        Route::post('create', 'store')->name('admin.products.store');

        Route::get('view/{id}', 'view')->name('admin.products.view');

        Route::get('edit/{id}', 'edit')->name('admin.products.edit');

        Route::put('edit/{id}', 'update')->name('admin.products.update');

        Route::get('search', 'search')->name('admin.products.search');

        Route::get('{id}/precio', 'precio')->name('admin.products.precio');

        Route::get('{id}/warehouses', 'warehouses')->name('admin.products.warehouses');

        Route::post('{id}/inventories/{warehouseId?}', 'storeInventories')->name('admin.products.inventories.store');

        Route::delete('{id}', 'destroy')->name('admin.products.delete');

        Route::post('mass-destroy', 'massDestroy')->name('admin.products.mass_delete');

        Route::controller(ActivityController::class)->prefix('{id}/activities')->group(function () {
            Route::get('', 'index')->name('admin.products.activities.index');
        });

        Route::controller(TagController::class)->prefix('{id}/tags')->group(function () {
            Route::post('', 'attach')->name('admin.products.tags.attach');

            Route::delete('', 'detach')->name('admin.products.tags.detach');
        });
    });

    /*
    | Adjuntos del producto. Fuera del grupo de ProductController porque tienen
    | su propio controlador, pero dentro del mismo middleware: los archivos
    | viven en el disco privado y solo se sirven con sesión.
    */
    Route::controller(ProductMediaController::class)
        ->prefix('products/{id}/medias')
        ->group(function () {
            Route::post('', 'store')->name('admin.products.medias.store');

            Route::patch('orden', 'orden')->name('admin.products.medias.orden');

            Route::patch('{media}/portada', 'portada')->name('admin.products.medias.portada');

            // A qué variante pertenece el adjunto (la ficha de 50 L), o a
            // ninguna (general del producto).
            Route::patch('{media}/variante', 'variante')->name('admin.products.medias.variante');

            Route::delete('{media}', 'destroy')->name('admin.products.medias.destroy');
        });

    Route::get('products/medias/{media}/archivo', [ProductMediaController::class, 'archivo'])
        ->name('admin.products.medias.archivo');

    /*
    | Envío de la ficha desde el inbox de WhatsApp. Estas rutas viven acá y no
    | en el módulo de WhatsApp a propósito: ver la cabecera de
    | ProductoInboxController.
    */
    Route::get('products/inbox/buscar', [ProductoInboxController::class, 'buscar'])
        ->name('admin.products.inbox.buscar');

    Route::post('products/inbox/enviar', [ProductoInboxController::class, 'enviar'])
        ->name('admin.products.inbox.enviar');
});
