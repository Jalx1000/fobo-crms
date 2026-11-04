<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Webkul\Whatsapp\Gateways\Contracts\Gateway;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\IngestInboundPayload;

/**
 * The single inbound webhook, provider-agnostic. The channel decides how to
 * handshake, how to authenticate and how to read the payload.
 *
 * A route may name its channel (`->defaults('channel', 'messenger')`) when the
 * endpoint belongs to one specific provider; the WhatsApp endpoint names none
 * and follows the configured default, so an installation that switches provider
 * keeps its URL.
 */
class WebhookController extends Controller
{
    public function __construct(protected GatewayManager $gateways) {}

    /**
     * Registration handshake. Providers without one (Kommo) get a 403.
     */
    public function verify(Request $request): Response
    {
        return $this->gateway($request)->verifyWebhook($request)
            ?? response('Forbidden', 403);
    }

    /**
     * Authenticate, queue, and get out of the way - the provider expects a fast
     * 200 and will unsubscribe us if we do work here.
     */
    public function receive(Request $request): JsonResponse
    {
        $gateway = $this->gateway($request);

        $auth = $gateway->authenticateWebhook($request);

        if (! $auth->ok) {
            Log::warning('Messaging webhook rejected', [
                'channel' => $gateway->key(),
                'reason'  => $auth->reason,
                'ip'      => $request->ip(),
                // Diagnóstico (20/10/2026): de qué objeto y página viene lo
                // rechazado, para saber si es otra app de Meta. Nada secreto.
                'object'  => $request->input('object'),
                'entries' => collect((array) $request->input('entry', []))->pluck('id')->filter()->unique()->values()->all(),
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        IngestInboundPayload::dispatch($gateway->key(), $request->all())
            ->onQueue(config('whatsapp.queue'));

        return response()->json(['status' => 'ok']);
    }

    /**
     * The channel this endpoint speaks for. An endpoint pinned to a channel
     * whose module is missing must not answer as if it were another one.
     */
    protected function gateway(Request $request): Gateway
    {
        $channel = $request->route()?->defaults['channel'] ?? null;

        if (! $channel) {
            return $this->gateways->active();
        }

        if (! $this->gateways->has($channel)) {
            throw new InvalidArgumentException("Messaging channel [{$channel}] is not registered.");
        }

        return $this->gateways->driver($channel);
    }
}
