<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Repositories\PersonRepository;

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
        $tables = $tablesEnv ? array_values(array_filter(array_map('trim', explode(',', $tablesEnv)))) : ['EXTERNAL_PGSQL_CHAT_TABLE=n8n_chat_histories_odonto'];
    }

    $messages = collect();

    foreach ($tables as $table) {
        $rows = DB::connection('external_pgsql')
            ->table($table)
            ->select('id', 'session_id', 'message')
            ->where('session_id', $email)
            ->orderBy('id', 'asc')
            ->get();

        foreach ($rows as $row) {
            $messages->push($row->message);
        }
    }

    return response()->json([
        'email' => $email,
        'messages' => $messages,
        'count' => $messages->count(),
    ]);
});

Route::middleware('auth:sanctum')->group(function () {
    /*
    | Los recursos de krayin/rest-api devuelven una lista fija de campos y ningún
    | atributo personalizado: `nit` se guarda bien pero la respuesta lo omite.
    | Esto lo complementa; no se parchea el recurso porque vive en vendor/.
    */
    Route::get('/v1/atributos/{entidad}/{id}', [\App\Http\Controllers\Api\AtributosController::class, 'show'])
        ->whereNumber('id');

    Route::get('/horarios', [\App\Http\Controllers\Api\ScheduleController::class, 'getHorarios']);
    Route::get('/disponibilidad', [\App\Http\Controllers\Api\ScheduleController::class, 'getDisponibilidad']);
});

/**
 * Webhook para ShareMeData
 */
Route::post('/webhooks/sharemedata', [\Webkul\Admin\Http\Controllers\ShareMeDataWebhookController::class, 'receive']);
