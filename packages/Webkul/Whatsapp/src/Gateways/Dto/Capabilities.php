<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * What a driver ACTUALLY implements today — not what the provider supports in
 * theory. The inbox composer renders from this, so a listed capability must
 * always work.
 */
final class Capabilities
{
    /**
     * Types that travel as a file. Everything else (text, location, contact) is
     * structured data on the wire.
     */
    public const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'sticker'];

    /**
     * Types WhatsApp lets you caption. Audio and sticker do NOT: Meta rejects
     * the field outright, so a caption on those has to go somewhere else.
     */
    public const CAPTIONABLE_TYPES = ['image', 'video', 'document'];

    /**
     * @param  array<int, string>  $send  e.g. ['text', 'image', 'document']
     * @param  array<int, string>  $receive
     */
    public function __construct(
        public array $send = [],
        public array $receive = [],
    ) {}

    public function canSend(string $type): bool
    {
        return in_array($type, $this->send, true);
    }

    public static function isMedia(string $type): bool
    {
        return in_array($type, self::MEDIA_TYPES, true);
    }

    public static function isCaptionable(string $type): bool
    {
        return in_array($type, self::CAPTIONABLE_TYPES, true);
    }

    public function toArray(): array
    {
        return [
            'send'    => $this->send,
            'receive' => $this->receive,
            // The composer needs this to decide whether the text box stays
            // usable while an attachment is staged.
            'captionable' => array_values(array_intersect($this->send, self::CAPTIONABLE_TYPES)),
        ];
    }
}
