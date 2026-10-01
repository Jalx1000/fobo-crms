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

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        IngestInboundPayload::dispatch($gateway->key(), $request->all())
            ->onQueue(config('whatsapp.queue'));

        return response()->json(['status' => 'ok']);
    }
}
