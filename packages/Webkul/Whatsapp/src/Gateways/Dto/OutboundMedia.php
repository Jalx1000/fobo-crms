<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * An attachment on its way out, normalized.
 *
 * Carries a path on the `public` disk rather than the bytes: the file is already
 * stored (the thread renders it the moment the advisor hits send) and the job
 * that delivers it runs in another process, where a megabytes-long payload has
 * no business sitting in the queue.
 */
final class OutboundMedia
{
    /**
     * @param  string  $type  image|audio|video|document|sticker
     * @param  string  $path  Path on the `public` disk (not a URL).
     * @param  string|null  $caption  Only image/video/document accept one; see
     *                                Capabilities::captionable().
     * @param  string|null  $filename  Shown to the recipient. Documents only.
     */
    public function __construct(
        public string $type,
        public string $path,
        public ?string $mime = null,
        public ?string $filename = null,
        public ?string $caption = null,
    ) {}
}
