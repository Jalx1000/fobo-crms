<?php

namespace Webkul\Whatsapp\Gateways;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Webkul\Whatsapp\Gateways\Contracts\Gateway;

/**
 * Shared plumbing for drivers. Every comparison is constant-time.
 */
abstract class BaseGateway implements Gateway
{
    public function __construct(protected array $config) {}

    /**
     * Most providers have no registration handshake.
     */
    public function verifyWebhook(Request $request): ?Response
    {
        return null;
    }

    /**
     * Default: no messaging window. WhatsApp gateways override to 24.
     */
    public function messagingWindowHours(): ?int
    {
        return null;
    }

    /**
     * HMAC of the RAW body. Never re-encode the JSON first — a re-serialized
     * body produces a different digest and every request fails.
     */
    protected function hmacMatches(Request $request, string $header, string $secret, string $algo = 'sha256', string $prefix = ''): bool
    {
        if ($secret === '') {
            return false;
        }

        $signature = (string) $request->header($header, '');

        $expected = $prefix.hash_hmac($algo, $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * For providers that sign nothing: the secret lives in the URL, so knowing
     * the URL *is* the credential.
     */
    protected function urlSecretMatches(Request $request): bool
    {
        $expected = (string) ($this->config['webhook_secret'] ?? '');

        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $request->route('secret'));
    }

    /**
     * Shared token sent in a header.
     */
    protected function headerTokenMatches(Request $request, string $header, string $expected): bool
    {
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $request->header($header, ''));
    }

    /**
     * Fail loudly at call time rather than firing a malformed request.
     */
    protected function required(string $key): string
    {
        $value = $this->config[$key] ?? null;

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Gateway [%s] is missing required config [%s].', $this->key(), $key));
        }

        return (string) $value;
    }
}
