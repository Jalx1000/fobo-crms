<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saca coordenadas de un link de Google Maps que el cliente pegó en el chat.
 *
 * Pasa tanto como el pin nativo: al 06/10/2026 había 5 ubicaciones compartidas
 * con el 📍 y 5 mensajes de texto con un `maps.app.goo.gl`, y de estos últimos
 * no se sacaba nada. El cliente cree que mandó su dirección y el agente ve una
 * URL que no sabe leer.
 */
class EnlaceDeMapa
{
    /**
     * 🔴 Lista BLANCA, y no negra, y se revisa en CADA salto de la redirección.
     *
     * Resolver una URL que escribió un desconocido es un SSRF: sin esto, un
     * link que redirige a 169.254.169.254 hace que el servidor le pida las
     * credenciales a la metadata del cloud y nosotros sigamos el salto.
     *
     * Por eso también se lee SÓLO el header `Location`: el cuerpo de la
     * respuesta nunca se mira ni se guarda.
     */
    protected const DOMINIOS = [
        'maps.app.goo.gl',
        'goo.gl',
        'g.co',
        'maps.google.com',
        'www.google.com',
        'google.com',
    ];

    /** Los acortadores: sólo estos hay que ir a resolver. */
    protected const ACORTADORES = ['maps.app.goo.gl', 'goo.gl', 'g.co'];

    protected const MAX_SALTOS = 4;

    /** ¿Vale la pena intentar? Sin red, para poder preguntarlo en caliente. */
    public static function hay(?string $texto): bool
    {
        return config('whatsapp.map_links.enabled', true)
            && static::urlEn($texto) !== null;
    }

    /**
     * El primer link de mapa del texto.
     */
    public static function urlEn(?string $texto): ?string
    {
        if (! $texto) {
            return null;
        }

        if (! preg_match_all('~https?://[^\s<>"\']+~i', $texto, $m)) {
            return null;
        }

        foreach ($m[0] as $url) {
            // La puntuación de la frase no es parte de la URL.
            $url = rtrim($url, '.,;:)]}');

            if (static::dominioPermitido($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Las coordenadas del link, resolviendo la redirección si hace falta.
     *
     * @return array{latitude: float, longitude: float, name: ?string}|null
     */
    public static function coordenadas(?string $texto): ?array
    {
        $url = static::urlEn($texto);

        if (! $url) {
            return null;
        }

        for ($salto = 0; $salto <= self::MAX_SALTOS; $salto++) {
            if ($punto = static::parsear($url)) {
                return $punto;
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if (! in_array($host, self::ACORTADORES, true)) {
                break; // una URL larga que ya no trae coordenadas: no hay nada más que seguir
            }

            $siguiente = static::destinoDe($url);

            if (! $siguiente) {
                break;
            }

            $url = $siguiente;
        }

        return null;
    }

    /**
     * El `Location` de UN salto, validado.
     */
    protected static function destinoDe(string $url): ?string
    {
        try {
            $destino = Http::withoutRedirecting()
                ->timeout((int) config('whatsapp.map_links.timeout', 6))
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SensiaCRM)'])
                ->get($url)
                ->header('Location');
        } catch (Throwable $e) {
            Log::warning('WhatsApp: no se pudo resolver un link de mapa.', [
                'host'  => parse_url($url, PHP_URL_HOST),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $destino) {
            return null;
        }

        // Un Location relativo se queda en el mismo host, que ya está validado.
        if (! preg_match('~^https?://~i', $destino)) {
            $base = parse_url($url);

            $destino = $base['scheme'].'://'.$base['host'].'/'.ltrim($destino, '/');
        }

        return static::dominioPermitido($destino) ? $destino : null;
    }

    protected static function dominioPermitido(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && in_array($host, self::DOMINIOS, true);
    }

    /**
     * Las coordenadas que puede traer una URL larga de Maps.
     *
     * 🔴 EL ORDEN IMPORTA, y no es el orden en que aparecen en la URL.
     *
     *   !3d<lat>!4d<lng>  es EL LUGAR
     *   /@<lat>,<lng>     es el CENTRO DEL VISOR
     *
     * No son lo mismo. Medido con un link real: `/@-17.7436362,-63.191432` y
     * `!3d-17.7520937!4d-63.1931235` están a ~950 m. Leer el `@` manda al
     * distribuidor a otra cuadra, así que el `@` es el último recurso y existe
     * sólo para los links que no traen el lugar.
     *
     * @return array{latitude: float, longitude: float, name: ?string}|null
     */
    public static function parsear(string $url): ?array
    {
        $nombre = static::nombreEn($url);

        // 1. El lugar.
        if (preg_match('~!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)~', $url, $m)) {
            return static::punto($m[1], $m[2], $nombre);
        }

        // 2. Coordenadas pedidas explícitamente (?q=, ?query=, ?ll=, ?daddr=).
        if (preg_match('~[?&](?:q|query|ll|daddr|destination)=(-?\d+\.\d+)(?:,|%2C)(-?\d+\.\d+)~i', $url, $m)) {
            return static::punto($m[1], $m[2], $nombre);
        }

        // 3. El visor, último recurso.
        if (preg_match('~/@(-?\d+\.\d+),(-?\d+\.\d+)~', $url, $m)) {
            return static::punto($m[1], $m[2], $nombre);
        }

        return null;
    }

    /** El nombre del lugar que Google pone en `/maps/place/<nombre>/`. */
    protected static function nombreEn(string $url): ?string
    {
        if (! preg_match('~/maps/place/([^/@]+)~', $url, $m)) {
            return null;
        }

        $nombre = trim(str_replace('+', ' ', urldecode($m[1])));

        // Google rellena con las coordenadas cuando el lugar no tiene nombre.
        if ($nombre === '' || preg_match('~^-?\d+\.\d+,\s*-?\d+\.\d+$~', $nombre)) {
            return null;
        }

        return mb_substr($nombre, 0, 120);
    }

    /**
     * @return array{latitude: float, longitude: float, name: ?string}|null
     */
    protected static function punto(string $lat, string $lng, ?string $nombre): ?array
    {
        $lat = (float) $lat;
        $lng = (float) $lng;

        if (abs($lat) > 90 || abs($lng) > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return null;
        }

        return ['latitude' => $lat, 'longitude' => $lng, 'name' => $nombre];
    }
}
