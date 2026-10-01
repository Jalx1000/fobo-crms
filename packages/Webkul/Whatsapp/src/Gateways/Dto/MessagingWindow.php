<?php

namespace Webkul\Whatsapp\Gateways\Dto;

use Carbon\CarbonInterface;

/**
 * The WhatsApp 24h service window for a conversation. Free-form text can only be
 * sent while it is open; outside it, an approved template is required.
 *
 * Applies to any gateway that delivers over WhatsApp (Cloud API and Kommo both
 * do). A non-WhatsApp gateway reports applies=false.
 */
final class MessagingWindow
{
    public function __construct(
        public bool $applies,
        public bool $open,
        public ?CarbonInterface $expiresAt = null,
    ) {}

    public static function notApplicable(): self
    {
        return new self(applies: false, open: true);
    }

    /**
     * Compute the window from the gateway's window length and the conversation's
     * last inbound message.
     *
     * @param  int|null  $hours  From the gateway; null means no window at all.
     * @param  CarbonInterface|null  $lastInboundAt  Null means the customer never
     *                                               wrote — the window is closed.
     */
    public static function compute(?int $hours, ?CarbonInterface $lastInboundAt): self
    {
        if ($hours === null) {
            return self::notApplicable();
        }

        if ($lastInboundAt === null) {
            return new self(applies: true, open: false);
        }

        $expiresAt = $lastInboundAt->copy()->addHours($hours);

        return new self(applies: true, open: now()->lt($expiresAt), expiresAt: $expiresAt);
    }

    public function secondsLeft(): ?int
    {
        if (! $this->applies || ! $this->open || ! $this->expiresAt) {
            return null;
        }

        return max(0, now()->diffInSeconds($this->expiresAt, false));
    }

    public function toArray(): array
    {
        return [
            'applies'      => $this->applies,
            'open'         => $this->open,
            'expires_at'   => $this->expiresAt?->toIso8601String(),
            'seconds_left' => $this->secondsLeft(),
        ];
    }
}
