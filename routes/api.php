<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $data['duration_minutes'] = $product->duration_minutes;

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

Route::get('/public/chat-history', function (Request $request) {
    $email = $request->query('email');

    if (! $email) {
        return response()->json(['error' => 'email es requerido'], 400);
    }

    $singleTable = env('EXTERNAL_PGSQL_CHAT_TABLE');
    if ($singleTable) {
        $tables = [trim($singleTable)];
    } else {
        $tablesEnv = env('EXTERNAL_PGSQL_CHAT_TABLES');
        $tables = $tablesEnv ? array_values(array_filter(array_map('trim', explode(',', $tablesEnv)))) : ['n8n_chat_histories_odonto'];
    }

    $messages = collect();

    try {
        foreach ($tables as $table) {
            $rows = DB::connection('external_pgsql')
                ->table($table)
                ->select('id', 'session_id', 'message')
                ->where('session_id', $email)
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $messages->push($row->message);
            }
        }
    } catch (\Illuminate\Database\QueryException $e) {
        \Illuminate\Support\Facades\Log::error('[ChatHistory] Error consultando historial', [
            'email'   => $email,
            'error'   => $e->getMessage(),
        ]);

        return response()->json([
            'email'    => $email,
            'messages' => [],
            'count'    => 0,
            'warning'  => 'No se pudo conectar al servicio de historial. Intente más tarde.',
        ], 503);
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('[ChatHistory] Error inesperado', [
            'email' => $email,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'email'    => $email,
            'messages' => [],
            'count'    => 0,
            'warning'  => 'Error interno. Intente más tarde.',
        ], 503);
    }

    return response()->json([
        'email'    => $email,
        'messages' => $messages,
        'count'    => $messages->count(),
    ]);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/horarios', [\App\Http\Controllers\Api\ScheduleController::class, 'getHorarios']);
    Route::get('/disponibilidad', [\App\Http\Controllers\Api\ScheduleController::class, 'getDisponibilidad']);
});

/**
 * Disponibilidad real (ShareMeData − citas locales) con fallback local.
 * Endpoint recomendado para clientes externos (agente IA) en lugar de
 * /horarios y /disponibilidad (deprecados) y /api/doctors/{id}/slots (solo local).
 */
Route::middleware(['auth:sanctum', 'throttle:doctor-availability'])->group(function () {
    Route::get('doctors/{id}/available-slots', [\App\Http\Controllers\Api\DoctorAvailabilityController::class, 'availableSlots'])
        ->where('id', '[1-9][0-9]*');
});

/**
 * Override del endpoint REST de actividades con validación médica (turno local + ShareMeData).
 * Debe estar registrado ANTES de que el vendor krayin/rest-api cargue sus rutas.
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::post('v1/activities', [\App\Http\Controllers\Api\V1\ActivityController::class, 'store']);
});

/**
 * Webhook para ShareMeData
 */
Route::middleware('throttle:60,1')->post('/webhooks/sharemedata', [\Webkul\Admin\Http\Controllers\ShareMeDataWebhookController::class, 'receive'])->name('admin.webhook.sharemedata');

/**
 * Webhook para Dropbox (notificaciones de cambios en carpeta SMD)
 */
Route::get('/webhooks/dropbox', [\Webkul\Admin\Http\Controllers\DropboxWebhookController::class, 'verify'])->name('admin.webhook.dropbox.verify');
Route::middleware('throttle:60,1')->post('/webhooks/dropbox', [\Webkul\Admin\Http\Controllers\DropboxWebhookController::class, 'handle'])->name('admin.webhook.dropbox');
