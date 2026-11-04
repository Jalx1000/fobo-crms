<?php

namespace Webkul\Messenger\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies a remote profile picture onto our own storage.
 *
 * Meta's CDN URLs are signed and expire within days, so hot-linking one means
 * every old thread eventually shows a broken avatar. Stored on the `public`
 * disk and served by URL - the same convention inbound WhatsApp media already
 * follows.
 */
class MediaLibrary
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function mirror(string $url): ?string
    {
        // The URL comes from an API response, not from us: refuse anything but
        // https, and never follow a redirect into a different scheme or host.
        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->accept('image/*')
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            $mime = (string) $response->header('Content-Type');

            if ($body === '' || strlen($body) > self::MAX_BYTES || ! str_starts_with($mime, 'image/')) {
                return null;
            }

            $path = 'messenger/avatars/'.date('Y/m').'/'.Str::random(32).$this->extensionFor($mime);

            Storage::disk('public')->put($path, $body);

            return Storage::disk('public')->url($path);
        } catch (Throwable) {
            // An avatar is decoration: never let it cost us the message.
            return null;
        }
    }

    protected function extensionFor(string $mime): string
    {
        return match (trim(explode(';', $mime)[0])) {
            'image/jpeg' => '.jpg',
            'image/png'  => '.png',
            'image/webp' => '.webp',
            'image/gif'  => '.gif',
            default      => '',
        };
    }
}
