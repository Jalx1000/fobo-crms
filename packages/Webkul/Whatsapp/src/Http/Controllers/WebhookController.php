<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;

/**
 * Single inbound webhook, provider-agnostic. The active gateway decides how to
 * handshake, how to authenticate, and how to read the payload.
 */
class WebhookController extends Controller
{
    public function __construct(protected GatewayManager $gateways) {}

    /**
     * Registration handshake. Providers without one (Kommo) get a 403.
     */
    public function verify(Request $request): Response
    {
        return $this->gateways->active()->verifyWebhook($request)
            ?? response('Forbidden', 403);
    }

    /**
     * Authenticate, queue, and get out of the way — the provider expects a fast
     * 200 and will unsubscribe us if we do work here.
     */
    public function receive(Request $request): JsonResponse
    {
        $gateway = $this->gateways->active();

        $auth = $gateway->authenticateWebhook($request);

        if (! $auth->ok) {
            Log::warning('WhatsApp webhook rejected', [
                'gateway' => $gateway->key(),
                'reason'  => $auth->reason,
                'ip'      => $request->ip(),
            ]);

            // DIAGNÓSTICO TEMPORAL (14/10/2026): ¿qué es lo que se rechaza?
            // Solo registra, no cambia la decisión. Sacar cuando se resuelva.
            $this->diagnosticarRechazo($request);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        IngestInboundPayload::dispatch($gateway->key(), $request->all())
            ->onQueue(config('whatsapp.queue'));

        return response()->json(['status' => 'ok']);
    }

    /**
     * DIAGNÓSTICO TEMPORAL. A qué cuenta va el evento, qué trae y qué secreto
     * del .env lo firmó. Nunca registra secretos ni el texto de los mensajes.
     */
    protected function diagnosticarRechazo(Request $request): void
    {
        try {
            $crudo = $request->getContent();
            $firma = (string) $request->header('X-Hub-Signature-256', '');

            $candidatos = [];

            foreach (['WHATSAPP_APP_SECRET', 'BUSINESS_PLATFORM_API_APP_SECRET', 'MESSENGER_APP_SECRET', 'INSTAGRAM_APP_SECRET'] as $nombre) {
                $secreto = (string) env($nombre, '');

                if ($secreto !== '' && hash_equals('sha256='.hash_hmac('sha256', $crudo, $secreto), $firma)) {
                    $candidatos[] = $nombre;
                }
            }

            $cambios = collect($request->input('entry', []))->flatMap(fn ($entry) => collect($entry['changes'] ?? [])->map(fn ($c) => [
                'entry_id'  => $entry['id'] ?? null,
                'field'     => $c['field'] ?? null,
                'phone_id'  => $c['value']['metadata']['phone_number_id'] ?? null,
                'mensajes'  => count($c['value']['messages'] ?? []),
                'estados'   => count($c['value']['statuses'] ?? []),
                'tipos'     => collect($c['value']['messages'] ?? [])->pluck('type')->all(),
                'referral'  => collect($c['value']['messages'] ?? [])->contains(fn ($m) => isset($m['referral'])),
            ]))->all();

            Log::warning('WhatsApp webhook rechazado: diagnóstico', [
                'object'          => $request->input('object'),
                'cambios'         => $cambios,
                'firma_valida_con' => $candidatos ?: 'ninguno',
                'bytes'           => strlen($crudo),
                'no_ascii'        => (bool) preg_match('/[^\x00-\x7F]/', $crudo),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Diagnóstico de webhook falló: '.$e->getMessage());
        }
    }
}
