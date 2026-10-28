<?php

namespace Webkul\Whatsapp\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Webkul\Admin\Services\PatientAppointments;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Services\EvalSandbox;

/**
 * Evals del agente: re-siembra bajo demanda, para correr antes de cada corrida.
 * Lo mismo que `php artisan whatsapp:eval-seed`, pero devuelve los ids nuevos.
 */
class EvalController extends Controller
{
    /**
     * POST /api/v1/whatsapp/eval/seed {clean?: bool}
     */
    public function seed(Request $request, PatientAppointments $appointments): JsonResponse
    {
        if (! EvalSandbox::allowed()) {
            return response()->json(['message' => 'Evals deshabilitado en este entorno.'], 403);
        }

        $clean = $request->boolean('clean');

        if (Artisan::call('whatsapp:eval-seed', $clean ? ['--clean' => true] : []) !== 0) {
            return response()->json(['message' => trim(Artisan::output())], 500);
        }

        if ($clean) {
            return response()->json(['contactos' => []]);
        }

        $contactos = collect((array) config('whatsapp.eval.contacts', []))->map(function ($contact) use ($appointments) {
            $conversation = Conversation::where('wa_phone', (string) $contact['phone'])->orderBy('id')->firstOrFail();

            return [
                'wa_id'           => $conversation->wa_phone,
                'conversation_id' => $conversation->id,
                'person_id'       => $conversation->person_id,
                'canal'           => $conversation->gateway === 'sandbox' ? 'sandbox' : 'real',
                'recordatorios'   => $appointments->remindersOf($conversation->id),
            ];
        })->values();

        return response()->json(['contactos' => $contactos]);
    }
}
