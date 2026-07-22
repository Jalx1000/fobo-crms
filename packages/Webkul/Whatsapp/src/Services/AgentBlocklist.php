<?php

namespace Webkul\Whatsapp\Services;

/**
 * Numbers the AI agent must never answer.
 *
 * The mirror of [[AgentTestMode]]: that one restricts the agent to a whitelist
 * while testing, this one bans specific numbers permanently. Both are consulted
 * from Conversation::aiEffective(), so a blocked number is silent on every path
 * — inbound ingestion, the notify job and the inbox composer.
 *
 * Blocking wins over everything: the per-conversation switch and the global
 * WHATSAPP_AI_ENABLED cannot re-enable the agent for a banned number.
 */
class AgentBlocklist
{
    /**
     * How many trailing digits decide whether two numbers are the same one.
     *
     * Same rule as the whitelist: phones reach us as 8 local digits from the
     * CRM and 11 with country code from Meta, so only the tail is comparable.
     */
    protected const MATCH_DIGITS = 8;

    /**
     * Whether this number is banned from the agent.
     */
    public static function blocks(?string $phone): bool
    {
        $numbers = self::numbers();

        if ($numbers === []) {
            return false;
        }

        $tail = PhoneNumber::tail($phone, self::MATCH_DIGITS);

        return $tail !== '' && in_array($tail, $numbers, true);
    }

    /**
     * The blocklist as comparable tails.
     *
     * @return array<int, string>
     */
    public static function numbers(): array
    {
        $raw = config('whatsapp.ai.blocked_numbers');

        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        $tails = [];

        foreach ($list as $number) {
            $tail = PhoneNumber::tail($number, self::MATCH_DIGITS);

            if ($tail !== '') {
                $tails[] = $tail;
            }
        }

        return array_values(array_unique($tails));
    }
}
