<?php

use Illuminate\Support\Facades\Route;
use Webkul\Inmobiliaria\Http\Controllers\Api\CatalogoController;
use Webkul\Inmobiliaria\Http\Controllers\Api\EnvioController;
use Webkul\Inmobiliaria\Http\Controllers\Api\HandoffController;
use Webkul\Inmobiliaria\Http\Controllers\Api\LeadController;

/*
|--------------------------------------------------------------------------
| API del catálogo para el agente de IA
|--------------------------------------------------------------------------
| Todo se identifica por `conversation_id` — que el CRM inyecta server-side en
| cada webhook — salvo el `codigo` del inmueble, que es público y es el único
| identificador que el modelo de lenguaje manipula.
*/

Route::prefix('api/v1/inmobiliaria')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::get('inmuebles', [CatalogoController::class, 'index']);
        Route::get('inmuebles/{codigo}', [CatalogoController::class, 'show']);

        Route::post('conversations/{id}/media', [EnvioController::class, 'media']);
        Route::post('conversations/{id}/ubicacion', [EnvioController::class, 'ubicacion']);
        Route::post('conversations/{id}/solicitud', [LeadController::class, 'solicitud']);
        Route::post('conversations/{id}/captacion', [LeadController::class, 'captacion']);
        Route::post('conversations/{id}/postventa', [LeadController::class, 'postventa']);
        Route::post('conversations/{id}/sin-interes', [LeadController::class, 'sinInteres']);

        Route::post('conversations/{id}/handoff', [HandoffController::class, 'derivar']);
    });

/*
| Descarga firmada, para el caso en que Meta todavía no tiene el archivo
| cacheado. Sin sesión, porque quien la consume es el servidor de Meta, pero
| firmada y de vida corta: la URL sirve para ese envío y deja de servir.
*/
Route::get('api/v1/inmobiliaria/media/{media}/archivo', [EnvioController::class, 'archivoFirmado'])
    ->middleware('signed')
    ->name('inmobiliaria.media.firmada');
