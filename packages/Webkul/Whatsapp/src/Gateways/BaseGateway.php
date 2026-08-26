<?php

namespace Webkul\Whatsapp\Gateways;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Webkul\Whatsapp\Exceptions\UnsupportedMessageException;
use Webkul\Whatsapp\Gateways\Contracts\Gateway;
use Webkul\Whatsapp\Gateways\Dto\OutboundLocation;
use Webkul\Whatsapp\Gateways\Dto\OutboundMedia;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Gateways\Dto\SendResult;
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
     * Default: no messaging window. WhatsApp gateways override to 24.
     */
    public function messagingWindowHours(): ?int
    {
        return null;
    }

    /**
     * Default: the provider exposes no media API. Cloud API overrides this.
     */
    public function downloadMedia(?string $mediaId): ?array
    {
        return null;
    }

    /**
     * Default: text only. A driver that declares 'image' (or any other media
     * type) in capabilities() must override this, or the composer offers a
     * button that fails at send time.
     */
    public function sendMedia(Conversation $conversation, OutboundMedia $media, ?string $replyToProviderId = null): SendResult
    {
        throw UnsupportedMessageException::type($this->key(), $media->type);
    }

    /**
     * Default: no location support.
     */
    public function sendLocation(Conversation $conversation, OutboundLocation $location, ?string $replyToProviderId = null): SendResult
    {
        throw UnsupportedMessageException::type($this->key(), 'location');
    }

    /**
     * Default: no template support.
     *
     * Keeping this here is what lets a new method land on the contract without
     * touching Kommo or Messenger: they simply keep refusing, loudly, the same
     * way they already refuse media.
     */
    public function sendTemplate(Conversation $conversation, OutboundTemplate $template, ?string $replyToProviderId = null): SendResult
    {
        throw UnsupportedMessageException::type($this->key(), 'template');
    }

    /**
     * Absolute filesystem path of a media file staged on the `public` disk.
     * Drivers stream from here instead of loading the bytes into the queue.
     */
    protected function mediaPath(OutboundMedia $media): string
    {
        $path = Storage::disk('public')->path($media->path);

        if (! is_readable($path)) {
            throw new RuntimeException(sprintf('El archivo adjunto ya no está disponible en el servidor [%s].', $media->path));
        }

        return $path;
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
