<?php

namespace Webkul\Whatsapp\Gateways;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Webkul\Whatsapp\Exceptions\CapabilityNotSupportedException;
use Webkul\Whatsapp\Gateways\Contracts\Gateway;
use Webkul\Whatsapp\Gateways\Dto\OutboundInteractive;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
use Webkul\Whatsapp\Gateways\Dto\TemplateHeader;
use Webkul\Whatsapp\Models\Conversation;

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
     * Default: the provider has no interactive messages.
     */
    public function interactiveSelection(array $rawMessage): ?array
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

    /*
    |--------------------------------------------------------------------------
    | Outbound defaults
    |--------------------------------------------------------------------------
    | Every send beyond plain text refuses by default, and each driver overrides
    | only what it really implements.
    |
    | This is deliberate, and it is what keeps the contract safe to grow: adding
    | a method to the interface with no default here would be a fatal error at
    | boot for every driver that has not been updated yet — the CRM would simply
    | not come up. With the defaults, a driver that lacks a capability answers
    | 501 and everything else keeps working.
    |
    | The refusal is loud on purpose. A silent no-op would let the caller — the
    | AI agent, most of the time — believe the customer was answered.
    */

    public function sendTemplate(
        Conversation $conversation,
        string $templateKey,
        array $variables = [],
        ?string $language = null,
        ?TemplateHeader $header = null,
    ): SendResult {
        throw new CapabilityNotSupportedException($this->key(), 'template');
    }

    public function sendMedia(
        Conversation $conversation,
        OutboundMedia $media,
        ?string $replyToProviderId = null,
    ): SendResult {
        throw new CapabilityNotSupportedException($this->key(), $media->kind);
    }

    public function sendLocation(
        Conversation $conversation,
        OutboundLocation $location,
        ?string $replyToProviderId = null,
    ): SendResult {
        throw new CapabilityNotSupportedException($this->key(), 'location');
    }

    public function sendInteractive(
        Conversation $conversation,
        OutboundInteractive $interactive,
        ?string $replyToProviderId = null,
    ): SendResult {
        throw new CapabilityNotSupportedException($this->key(), 'interactive');
    }

    /**
     * Default: the provider exposes no downloadable media.
     *
     * Null rather than an exception: unlike a send, nobody is waiting on this
     * and there is nothing the user could do about it. The message is still
     * stored, just without its file.
     */
    public function mediaReference(array $rawMessage): ?array
    {
        return null;
    }

    public function fetchMedia(string $mediaId): ?array
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
