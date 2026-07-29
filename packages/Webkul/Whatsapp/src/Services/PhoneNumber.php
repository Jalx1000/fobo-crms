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
     *
     * A bare national number (no country code — e.g. an 8-digit Bolivian mobile
     * typed into the CRM by hand) gets the configured default prepended, so it
     * lands on the same conversation as the international form the provider sends
     * ("59175640698") instead of fragmenting into a separate "+75640698" thread.
     */
    public static function normalize(?string $value): string
    {
        $digits = self::digits($value);

        if ($digits === '') {
            return '';
        }

        $cc = (string) config('whatsapp.default_country_code', '591');

        // National numbers are <= 8 digits here; anything longer already carries
        // a country code. Skip if it already starts with the default cc.
        if ($cc !== '' && strlen($digits) <= 8 && ! str_starts_with($digits, $cc)) {
            $digits = $cc.$digits;
        }

        return '+'.$digits;
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
