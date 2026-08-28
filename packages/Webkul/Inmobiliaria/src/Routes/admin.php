<?php

use Illuminate\Support\Facades\Route;
use Webkul\Inmobiliaria\Http\Controllers\InmuebleController;
use Webkul\Inmobiliaria\Http\Controllers\InmuebleMediaController;

/*
|--------------------------------------------------------------------------
| Catálogo de inmuebles (admin)
|--------------------------------------------------------------------------
| Montadas bajo el admin path con web + admin_locale + user en el provider.
*/

Route::controller(InmuebleController::class)
    ->prefix('inmuebles')
    ->name('admin.inmuebles.')
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{id}/edit', 'edit')->name('edit');
        Route::put('{id}', 'update')->name('update');
        Route::delete('{id}', 'destroy')->name('destroy');
    });

Route::controller(InmuebleMediaController::class)
    ->prefix('inmuebles/{inmueble}/medias')
    ->name('admin.inmuebles.medias.')
    ->group(function () {
        Route::post('/', 'store')->name('store');
        Route::patch('{media}/portada', 'portada')->name('portada');
        Route::patch('orden', 'orden')->name('orden');
        Route::delete('{media}', 'destroy')->name('destroy');
    });

/*
| El archivo se sirve por una ruta autenticada, no desde storage público: la
| galería de un inmueble no debe ser visible para cualquiera que adivine la
| URL (riesgo R5 de la spec).
*/
Route::get('inmuebles/medias/{media}/archivo', [InmuebleMediaController::class, 'archivo'])
    ->name('admin.inmuebles.medias.archivo');

/*
| Enviar la galería de un inmueble al cliente desde el inbox de WhatsApp.
| Vive acá y no en el módulo WhatsApp porque necesita resolver el inmueble por
| su código, que es conocimiento del catálogo.
*/
Route::post('inmuebles/enviar-galeria', [\Webkul\Inmobiliaria\Http\Controllers\GaleriaInboxController::class, 'enviar'])
    ->name('admin.inmuebles.enviar-galeria');
