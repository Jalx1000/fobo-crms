<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * A file on its way out.
 *
 * Carries a public URL rather than bytes: Meta fetches the file itself, and the
 * mime we declare has to match what that URL actually serves — hence
 * MediaController, which sets the header from what was stored with the message
 * instead of letting the web server guess from the extension.
 */
final class OutboundMedia
{
    /**
     * @param  string  $kind  image | document | audio | video | sticker
     */
    public function __construct(
        public string $kind,
        public string $url,
        public ?string $filename = null,
        public ?string $caption = null,
        public ?string $mime = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            kind: (string) ($data['kind'] ?? 'document'),
            url: (string) ($data['url'] ?? ''),
            filename: $data['filename'] ?? null,
            caption: $data['caption'] ?? null,
            mime: $data['mime'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'kind'     => $this->kind,
            'url'      => $this->url,
            'filename' => $this->filename,
            'caption'  => $this->caption,
            'mime'     => $this->mime,
        ];
    }

    public function label(): string
    {
        return $this->caption ?: ($this->filename ?: ucfirst($this->kind));
    }
}
