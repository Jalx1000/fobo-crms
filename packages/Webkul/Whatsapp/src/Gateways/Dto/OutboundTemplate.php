<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * An approved HSM template, ready to send.
 *
 * Templates are the only way to reach a contact once the 24h service window has
 * closed: WhatsApp rejects free-form messages outside it. The text is fixed at
 * approval time; only the numbered placeholders vary per send.
 *
 * A file (our quote PDF) can only travel in the header - a template body takes
 * text and nothing else. The header carries the *file*, not a provider media
 * id: uploading is Cloud API's business, and resolving the id before we get
 * here would leak Meta's two-step upload into the provider-agnostic queue job.
 */
final class OutboundTemplate
{
    /**
     * @param  string  $name  Exact name approved in WhatsApp Manager.
     * @param  string  $language  The template's approved language code ('es',
     *                            'es_MX'…). A mismatch is not a fallback: Meta
     *                            answers 132001 and the message is lost.
     * @param  array<int, string>  $bodyParams  Positional values for {{1}}, {{2}}…
     *                                          None may be an empty string -
     *                                          Meta rejects the whole message
     *                                          with 132000.
     * @param  OutboundMedia|null  $headerMedia  The file for a DOCUMENT/IMAGE
     *                                           header, already staged on disk.
     */
    public function __construct(
        public string $name,
        public string $language,
        public array $bodyParams = [],
        public ?OutboundMedia $headerMedia = null,
    ) {}

    /**
     * Rebuild from the `payload` column of a queued message.
     *
     * The media rides separately: SendMessage already reconstructs it from the
     * message row's own media columns, which is where it lives for every
     * outbound file regardless of how it gets delivered.
     */
    public static function fromArray(array $data, ?OutboundMedia $headerMedia = null): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            language: (string) ($data['language'] ?? ''),
            bodyParams: array_values((array) ($data['body_params'] ?? [])),
            headerMedia: $headerMedia,
        );
    }

    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'language'    => $this->language,
            'body_params' => $this->bodyParams,
        ];
    }
}
