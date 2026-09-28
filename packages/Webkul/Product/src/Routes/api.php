<?php

use Illuminate\Support\Facades\Route;
use Webkul\Product\Http\Controllers\Api\AgenteController;

/*
|--------------------------------------------------------------------------
| API del catálogo de productos para el agente de IA
|--------------------------------------------------------------------------
| La conversación se identifica siempre por `conversation_id`, que el CRM
| inyecta server-side en cada webhook. Lo único que el modelo de lenguaje
| manipula es el `sku`, que es público y ya aparece en las cotizaciones.
|
| Las rutas de los archivos NUNCA viajan por el modelo: el agente pide
| "3 imágenes del SKU X" y el CRM decide cuáles, en qué orden y por qué vía.
*/

Route::prefix('api/v1/productos')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::get('catalogo', [AgenteController::class, 'catalogo']);

        /*
        | Quién atiende un producto en una ciudad (round robin, sin mover el
        | turno). Va antes de `catalogo/{sku}` solo por orden de lectura: no
        | compiten.
        */
        Route::get('responsables', [AgenteController::class, 'responsablesDe']);
        Route::get('catalogo/{sku}', [AgenteController::class, 'ficha']);

        /*
        | POST y no GET: los criterios son un objeto con claves acentuadas
        | ("Tamaño") y valores con espacios. En query string eso se escapa
        | distinto según quién lo arme, y una diferencia de encoding se ve como
        | "no hay precio" en vez de como un error.
        */
        Route::post('catalogo/{sku}/precio', [AgenteController::class, 'precio']);

        Route::post('conversations/{id}/media', [AgenteController::class, 'media']);

        /*
        | El PASO 5 hecho dato: deja una cotización de verdad para que el asesor
        | la revise, en vez de una conversación que hay que leer entera.
        */
        Route::post('conversations/{id}/cotizacion', [AgenteController::class, 'cotizacion']);

        /*
        | Leer una cotización con sus líneas. El endpoint genérico de Krayin
        | (`/api/v1/quotes/{id}`) no las incluye y responde 500 cuando no
        | existe; acá vienen los ítems y el 404 es un 404.
        */
        Route::get('cotizaciones/{id}', [AgenteController::class, 'verCotizacion'])->whereNumber('id');
    });
