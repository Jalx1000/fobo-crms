<?php

namespace Webkul\Whatsapp\Services;

class PhoneNumber
{
    /**
     * Strip everything but digits.
     */
    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    /**
     * Normalize to a simple E.164-ish string ("+<digits>").
     */
    public static function normalize(?string $value): string
    {
        $digits = self::digits($value);

        return $digits === '' ? '' : '+'.$digits;
    }

    /**
     * The trailing portion of the number used for fuzzy matching against stored
     * contact numbers (handles inconsistent country/area code storage).
     */
    public static function tail(?string $value, int $length = 8): string
    {
        return substr(self::digits($value), -$length);
    }
}
