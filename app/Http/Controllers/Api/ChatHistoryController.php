<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * El historial de la memoria del agente de n8n, para la pestaña "Historial IA".
 *
 * Vivía como closure en routes/api.php y leía `env()` en tiempo de request. Eso
 * es una bomba: fuera de los archivos de config, `env()` devuelve null en cuanto
 * alguien corre `config:cache` —lo normal en producción— y el nombre de tabla
 * caía a un literal mal armado (`'EXTERNAL_PGSQL_CHAT_TABLE=n8n_chat_histories_odonto'`,
 * con el nombre de la variable pegado adelante). La query explotaba y el asesor
 * leía "No se pudo obtener el historial" sin más pistas.
 *
 * Ahora las tablas se leen de `config('chat_history.tables')`, que sí sobrevive
 * al cacheo.
 */
class ChatHistoryController extends Controller
{
    /**
     * Los mensajes de una sesión, en orden.
     *
     * La clave es el `session_id` con el que n8n guarda la conversación. En este
     * CRM resulta ser el email sintético del contacto (`<telefono>@whatsapp…`),
     * que es también lo que la ficha tiene cargado como email — de ahí el nombre
     * del parámetro.
     */
    public function byEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|max:255',
        ]);

        $session = trim($data['email']);

        $tables = (array) config('chat_history.tables', []);

        if (! $tables) {
            return response()->json([
                'error' => 'No hay tablas de historial configuradas (EXTERNAL_PGSQL_CHAT_TABLE).',
            ], 500);
        }

        $messages = collect();

        foreach ($tables as $table) {
            $rows = DB::connection('external_pgsql')
                ->table($table)
                ->select('id', 'session_id', 'message')
                ->where('session_id', $session)
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $messages->push($row->message);
            }
        }

        return response()->json([
            'email'    => $session,
            'messages' => $messages->values(),
            'count'    => $messages->count(),
        ]);
    }
}
