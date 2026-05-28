<?php

namespace Webkul\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Jobs\ProcessDropboxNotification;

class DropboxWebhookController extends Controller
{
    /**
     * Verificación inicial (handshake) de Dropbox.
     * GET /api/webhooks/dropbox?challenge=abc123
     */
    public function verify(Request $request)
    {
        $challenge = $request->query('challenge', '');

        Log::info('[DropboxWebhook] Handshake recibido', [
            'challenge' => $challenge,
            'ip'        => $request->ip(),
        ]);

        return response($challenge, 200)
            ->header('Content-Type', 'text/plain')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Recibe notificación de cambio de Dropbox.
     * POST /api/webhooks/dropbox
     */
    public function handle(Request $request)
    {
        $appSecret = config('smd.dropbox.app_secret', '');
        $signature = $request->header('X-Dropbox-Signature', '');
        $body      = $request->getContent();

        Log::info('[DropboxWebhook] POST recibido', [
            'ip'             => $request->ip(),
            'has_signature'  => ! empty($signature),
            'body_length'    => strlen($body),
            'body_preview'   => substr($body, 0, 200),
        ]);

        if (! $appSecret || ! hash_equals(hash_hmac('sha256', $body, $appSecret), $signature)) {
            Log::warning('[DropboxWebhook] Firma invalida', [
                'ip'               => $request->ip(),
                'signature_recv'   => $signature,
                'signature_expect' => hash_hmac('sha256', $body, $appSecret),
            ]);

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        Log::info('[DropboxWebhook] Firma valida — despachando job', [
            'ip' => $request->ip(),
        ]);

        ProcessDropboxNotification::dispatch();

        return response()->json(['ok' => true]);
    }
}
