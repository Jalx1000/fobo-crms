<?php

namespace Webkul\Core\Services;

/**
 * Phone number normalization shared by every module that has to decide whether
 * two numbers are the same person: WhatsApp inbound, lead creation over the
 * API, contact lookup. Kept in Core precisely because it must give the same
 * answer everywhere — a second copy of these rules is a duplicate contact
 * waiting to happen.
 */
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
     * Lo que ya viene con "+" no se toca: es un número internacional, y también es
     * como están guardadas las conversaciones, que se buscan por coincidencia exacta.
     */
    public static function normalize(?string $value): string
    {
        $digits = self::digits($value);

        if ($digits === '') {
            return '';
        }

        return '+'.(str_starts_with(ltrim((string) $value), '+') ? $digits : self::conPais($digits));
    }

    /**
     * Completa el país de un número cargado a mano, según PHONE_DEFAULT_COUNTRY. Sin
     * esto, "388 576-3067" sale como +388…, que para WhatsApp no es Argentina, y Meta
     * rechaza el envío. Sin la variable no hace nada: Core lo comparten CRMs de otros
     * países. Sólo cubre las formas sin ambigüedad; el resto queda como vino.
     */
    protected static function conPais(string $digits): string
    {
        if (config('app.phone_default_country') !== 'AR') {
            return $digits;
        }

        return match (true) {
            (bool) preg_match('/^[123]\d{9}$/', $digits)    => '549'.$digits,             // 388 576-3067
            (bool) preg_match('/^0[123]\d{9}$/', $digits)   => '549'.substr($digits, 1),  // 0388 576-3067
            (bool) preg_match('/^54[123]\d{9}$/', $digits)  => '549'.substr($digits, 2),  // 54 388 576-3067
            default                                         => $digits,
        };
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
     * Do these two numbers reach the same phone? True when one is a suffix of
     * the other, which is how the same line ends up stored as "76616013",
     * "591 76616013" and "+59176616013" across the CRM.
     */
    public static function same(?string $a, ?string $b, int $minLength = 7): bool
    {
        $a = self::digits($a);
        $b = self::digits($b);

        if (strlen($a) < $minLength || strlen($b) < $minLength) {
            return false;
        }

        return str_ends_with($a, $b) || str_ends_with($b, $a);
    }
}
