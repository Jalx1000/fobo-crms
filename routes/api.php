<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/public/products', function (Request $request) {
    $query = Product::query()->with('attribute_values');

    $search = $request->query('q');
    if ($search) {
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', '%'.$search.'%')
                ->orWhere('sku', 'like', '%'.$search.'%');
        });
    }

    $limit = (int) $request->query('limit');
    if ($limit > 0) {
        $query->limit($limit);
    }

    $available = $request->query('disponible');
    if ($available !== null) {
        $attribute = app(AttributeRepository::class)->getAttributeByCode('disponible');

        if ($attribute) {
            $query->whereHas('attribute_values', function ($q) use ($attribute, $available) {
                $q->where('attribute_id', $attribute->id)
                    ->where('boolean_value', (int) $available);
            });
        }
    }

    $valueRepo = app(AttributeValueRepository::class);

    $products = $query->orderBy('created_at', 'desc')->get()->map(function ($product) use ($valueRepo) {
        $data = $product->attributesToArray();

        foreach ($product->getCustomAttributes() as $attribute) {
            $raw = $product->getCustomAttributeValue($attribute);
            $data[$attribute->code] = $attribute->type === 'boolean'
                ? $raw
                : $valueRepo->getAttributeLabel($raw, $attribute);
        }

        return $data;
    });

    return response()->json(['data' => $products]);
});

/*
 * Historial de la memoria del agente de IA.
 *
 * Antes esta ruta era pública: recibía un email por query string y devolvía la
 * conversación completa. Como el `session_id` de n8n es `<telefono>@whatsapp…`,
 * con sólo saber un número de teléfono se leía todo lo que un cliente habló,
 * desde internet y sin credenciales.
 *
 * Queda detrás de un token para que la siga consumiendo una máquina (n8n), y se
 * publica además bajo el admin —abajo— para la pestaña del CRM, que autentica
 * por sesión y no por token.
 */
Route::middleware('auth:sanctum')->get('/public/chat-history', [
    \App\Http\Controllers\Api\ChatHistoryController::class, 'byEmail',
])->name('api.chat-history');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/horarios', [\App\Http\Controllers\Api\ScheduleController::class, 'getHorarios']);
    Route::get('/disponibilidad', [\App\Http\Controllers\Api\ScheduleController::class, 'getDisponibilidad']);

    // Historial de pedidos por teléfono, en una sola llamada y sin depender de
    // cómo esté escrito el número (con o sin código de país, con o sin "+").
    Route::get('/pedidos/por-telefono', [\App\Http\Controllers\Api\LeadLookupController::class, 'byPhone'])
        ->name('api.pedidos.por-telefono');

    // La ficha del contacto por teléfono, con sus atributos personalizados
    // (Ciudad, Sucursal). El /search de la REST API no sirve acá: matchea el
    // número con un LIKE crudo sobre el JSON y no devuelve atributos custom.
    Route::get('/personas/por-telefono', [\App\Http\Controllers\Api\PersonLookupController::class, 'byPhone'])
        ->name('api.personas.por-telefono');
});

/**
 * Webhook para ShareMeData
 */
Route::post('/webhooks/sharemedata', [\Webkul\Admin\Http\Controllers\ShareMeDataWebhookController::class, 'receive']);
