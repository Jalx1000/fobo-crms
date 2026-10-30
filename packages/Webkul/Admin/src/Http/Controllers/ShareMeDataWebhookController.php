<?php

namespace Webkul\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\IncomingAppointmentService;

class ShareMeDataWebhookController extends Controller
{
    public function __construct(
        protected IncomingAppointmentService $incomingAppointmentService
    ) {}

    public function receive(Request $request): JsonResponse
    {
        $secret = (string) config('services.sharemedata.webhook_secret');

        // Sin secreto configurado se RECHAZA, no se deja pasar: este endpoint
        // crea pacientes, leads, citas y doctores. Antes, un .env sin
        // SHAREMEDATA_WEBHOOK_SECRET lo dejaba abierto a cualquiera (AUD-01).
        if ($secret === '') {
            Log::warning('ShareMeData Webhook: rechazado, SHAREMEDATA_WEBHOOK_SECRET no está configurado', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = $request->header('X-Webhook-Secret') ?? $request->query('secret', '');

        if (! hash_equals($secret, (string) $token)) {
            Log::warning('ShareMeData Webhook: token inválido', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'physician._id'   => 'required|string',
            'patient.phone'   => 'required|string',
            'slot.start'      => 'required|string',
            'slot.end'        => 'required|string',
        ]);

        // validate() returns only validated keys - merge back full payload for service
        $data = $request->all();

        Log::info('ShareMeData Webhook: payload recibido', ['ip' => $request->ip(), '_id' => $data['_id'] ?? null]);

        try {
            $result = $this->incomingAppointmentService->processWebhook($data);

            // 200 si el evento ya estaba registrado (reintento), 201 si se creó.
            return response()->json(['success' => true, ...$result], empty($result['duplicate']) ? 201 : 200);
        } catch (\Exception $e) {
            Log::error('ShareMeData Webhook: Error procesando cita', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Error interno'], 500);
        }
    }
}
