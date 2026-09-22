<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * A pin on its way out. Name and address are optional but worth sending:
 * without them WhatsApp renders bare coordinates, which nobody can act on.
 */
final class OutboundLocation
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $name = null,
        public ?string $address = null,
    ) {}

    /**
     * Rebuild from the `payload` column of a persisted message.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            latitude: (float) ($data['latitude'] ?? 0),
            longitude: (float) ($data['longitude'] ?? 0),
            name: $data['name'] ?? null,
            address: $data['address'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
            'name'      => $this->name,
            'address'   => $this->address,
        ];
    }

    /**
     * What the thread shows as the message body, so the conversation-list
     * preview and any text-only export still say something useful.
     */
    public function label(): string
    {
        return $this->name
            ?: ($this->address ?: sprintf('%s, %s', $this->latitude, $this->longitude));
    }
}
