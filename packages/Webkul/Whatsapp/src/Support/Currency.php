<?php

namespace Webkul\Whatsapp\Support;

/**
 * El símbolo de la moneda del CRM, para las vistas de WhatsApp.
 *
 * El módulo mostraba "$" fijo, que en kohlberg es sencillamente falso: la
 * instalación cobra en bolivianos (APP_CURRENCY=BOB). No alcanza con delegar en
 * ICU — core()->currencySymbol('BOB') devuelve "BOB", no "Bs" — así que las
 * monedas cuyo símbolo corto ICU no conoce se listan en config y el resto cae
 * en el formateador del sistema.
 */
class Currency
{
    public static function symbol(): string
    {
        $code = (string) config('app.currency', 'USD');

        $overrides = (array) config('whatsapp.ui.currency_symbols', []);

        if (isset($overrides[$code])) {
            return (string) $overrides[$code];
        }

        $symbol = core()->currencySymbol($code);

        // Si ICU no tiene símbolo corto devuelve el propio código; mostrarlo es
        // preferible a mostrar el de otra moneda.
        return $symbol ?: $code;
    }
}
