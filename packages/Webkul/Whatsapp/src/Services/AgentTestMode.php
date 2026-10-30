<?php

namespace Webkul\Whatsapp\Services;

class AgentTestMode
{
    /**
     * How many trailing digits decide whether two numbers are the same one.
     *
     * Patient phones reach us in every shape: 8 local digits from the CRM,
     * 11 with country code from Meta, sometimes a leading '+' or a 0. Comparing
     * the tail is what makes "76616013" and "59176616013" the same person.
     */
    protected const MATCH_DIGITS = 8;

    /**
     * Whether the agent is restricted to a whitelist of numbers.
     */
    public static function active(): bool
    {
        return (bool) config('whatsapp.ai.test_mode') && self::numbers() !== [];
    }

    /**
     * Whether the agent is allowed to answer this number.
     *
     * Test mode is a restriction, never a grant: it can only silence the agent
     * on numbers outside the list, and the per-conversation switch still has
     * the last word for the ones inside it.
     */
    public static function allows(?string $phone): bool
    {
        if (! self::active()) {
            return true;
        }

        $tail = PhoneNumber::tail($phone, self::MATCH_DIGITS);

        return $tail !== '' && in_array($tail, self::numbers(), true);
    }

    /**
     * The whitelist as comparable tails.
     *
     * @return array<int, string>
     */
    public static function numbers(): array
    {
        $raw = config('whatsapp.ai.test_numbers');

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
