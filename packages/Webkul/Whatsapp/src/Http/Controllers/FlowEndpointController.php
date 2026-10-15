<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Webkul\Whatsapp\Services\Flows\FlowCrypto;
use Webkul\Whatsapp\Services\Flows\FlowDefinitions;
use Webkul\Whatsapp\Services\Flows\FlowToken;

/**
 * Endpoint de datos de WhatsApp Flows: POST /api/v1/whatsapp/flows/endpoint
 *
 * Público (lo llama Meta), protegido por dos cosas: la firma X-Hub-Signature-256
 * con el app secret y el cifrado con nuestra clave. Códigos que espera Meta:
 * 421 si no se puede descifrar (re-descarga la clave pública y reintenta).
 */
class FlowEndpointController extends Controller
{
    public function handle(Request $request, FlowCrypto $crypto): Response
    {
        if (! $this->signatureIsValid($request)) {
            Log::warning('[WhatsAppFlow] Firma inválida en el endpoint de Flows', ['ip' => $request->ip()]);

            return response('', 432);
        }

        $keyPath = (string) config('whatsapp.flows.private_key_path');

        if (! is_readable($keyPath)) {
            Log::error('[WhatsAppFlow] No se encuentra la clave privada de Flows: correr `php artisan whatsapp:flows:keys`', ['path' => $keyPath]);

            return response('', 500);
        }

        try {
            $decrypted = $crypto->decryptRequest(
                $request->json()->all(),
                (string) file_get_contents($keyPath),
                config('whatsapp.flows.private_key_passphrase'),
            );
        } catch (RuntimeException $e) {
            Log::warning('[WhatsAppFlow] No se pudo descifrar el pedido', ['error' => $e->getMessage()]);

            return response('', 421);
        }

        try {
            $result = $this->route($decrypted['payload']);
        } catch (Throwable $e) {
            Log::error('[WhatsAppFlow] Error procesando el pedido del Flow', [
                'screen' => $decrypted['payload']['screen'] ?? null,
                'action' => $decrypted['payload']['action'] ?? null,
                'error'  => $e->getMessage(),
            ]);

            return response('', 500);
        }

        return response($crypto->encryptResponse($result, $decrypted['aes_key'], $decrypted['iv']), 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Un solo endpoint para todos los formularios: el ping y los avisos de error
     * de Meta se contestan acá; el resto va al formulario que dice el token
     * (paciente_tercero, cancelar_cita, reprogramar_cita).
     */
    protected function route(array $payload): array
    {
        if (($payload['action'] ?? null) === 'ping') {
            return ['data' => ['status' => 'active']];
        }

        if (isset($payload['data']['error'])) {
            Log::warning('[WhatsAppFlow] Meta reportó un error en una respuesta del endpoint', [
                'flow'   => FlowToken::flow($payload['flow_token'] ?? null),
                'screen' => $payload['screen'] ?? null,
                'error'  => $payload['data']['error'],
                'detail' => $payload['data']['error_message'] ?? null,
            ]);

            return ['data' => ['acknowledged' => true]];
        }

        $flow = FlowToken::flow($payload['flow_token'] ?? null);

        // Token inválido o desconocido: lo atiende el formulario de paciente, que
        // ya sabe responder "no pudimos validar este formulario".
        $key = FlowDefinitions::exists($flow) ? $flow : FlowToken::DEFAULT_FLOW;

        return app(FlowDefinitions::handler($key))->handle($payload);
    }

    /**
     * Sin app secret configurado se rechaza: el endpoint escribe en el CRM.
     */
    protected function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('whatsapp.cloud.app_secret');
        $header = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), substr($header, 7));
    }
}
