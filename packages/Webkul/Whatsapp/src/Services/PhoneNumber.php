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

    /**
     * Digits with the default country code prepended when it's missing, so a
     * locally-stored number (e.g. Bolivian 8-digit "78164851") becomes a full
     * international one ("59178164851") before it reaches a provider.
     *
     * Left untouched if it already starts with the country code or if no code
     * is configured. Returns digits only (no '+').
     */
    /**
     * Local numbers, in digits, that may still be missing their country code.
     *
     * Bolivian numbers are 8 digits (mobile and landline alike), so anything
     * longer already carries a country code - prefixing it would corrupt a
     * foreign number: "5493875154445" (Argentina) became "5915493875154445".
     */
    protected const LOCAL_LENGTH = 8;

    public static function withCountryCode(?string $value, ?string $countryCode): string
    {
        $digits = self::digits($value);
        $cc = self::digits($countryCode);

        if ($digits === '' || $cc === '' || str_starts_with($digits, $cc)) {
            return $digits;
        }

        // Only a bare local number gets the code. A longer number is already
        // international (foreign or with another prefix) and is left alone.
        if (strlen($digits) !== self::LOCAL_LENGTH) {
            return $digits;
        }

        return $cc.$digits;
    }
}
