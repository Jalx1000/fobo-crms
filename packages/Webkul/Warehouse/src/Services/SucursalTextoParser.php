<?php

namespace Webkul\Warehouse\Services;

/**
 * Separa el texto libre de una sucursal en dirección, horarios y enlace de mapa.
 *
 * El equipo carga todo junto en `contact_address.address`, en el orden que le
 * queda cómodo (horarios arriba o abajo, con o sin encabezado). El CRM no tiene
 * campos propios para horarios ni mapa y no se agregan (decisión del 16/10/2026:
 * nada de UI), así que el endpoint del agente lo separa en cada lectura.
 *
 * Solo separa y limpia formato: nunca reescribe ni completa el contenido. Ante
 * la duda una línea queda en la dirección, para no perder información.
 */
class SucursalTextoParser
{
    /**
     * "Horarios de atención", con o sin dos puntos: es un rótulo, no un horario.
     */
    protected const ENCABEZADO = '/^horarios?\s+de\s+atenci[oó]n\s*:?$/iu';

    /**
     * Nombres de día. Sin "mar" ni "mie" abreviados: chocan con calles ("Av. del Mar").
     * "vienes" es una errata real de la sucursal Cochabamba.
     */
    protected const DIA = '/(?<!\pL)(lunes|martes|mi[eé]rcoles|jueves|viernes|vienes|s[aá]bados?|domingos?|feriados?|lun|vie|s[aá]b|dom)(?!\pL)/iu';

    /**
     * Una hora con formato de hora: 9:00, 9hrs, 7 a. m., 3pm. Un número suelto
     * ("#275", "15 de Abril", "N° 85") no alcanza.
     */
    protected const HORA = '/\b\d{1,2}:\d{2}\b|\b\d{1,2}\s*hrs?\b|\b\d{1,2}(?::\d{2})?\s*[ap]\.?\s?m\b\.?/iu';

    protected const URL = '/https?:\/\/\S+/iu';

    /**
     * @return array{direccion: ?string, horarios: ?string, mapa_url: ?string}
     */
    public function parse(?string $texto): array
    {
        $mapaUrl = null;
        $lineas = [];

        foreach (preg_split('/\R/u', (string) $texto) as $linea) {
            preg_match_all(self::URL, $linea, $urls);

            foreach ($urls[0] as $url) {
                $mapaUrl ??= $this->esGoogleMaps($url) ? $url : null;
            }

            // Cualquier URL sale del texto, sea de Maps o no: al cliente no le
            // sirve un enlace suelto metido en la dirección.
            $linea = $this->limpiar(preg_replace(self::URL, '', $linea));

            if ($linea !== '' && ! preg_match(self::ENCABEZADO, $linea)) {
                $lineas[] = $linea;
            }
        }

        $horarios = [];
        $direccion = [];

        foreach ($lineas as $linea) {
            $tieneDia = (bool) preg_match(self::DIA, $linea);
            $tieneHora = (bool) preg_match(self::HORA, $linea);

            if (! $tieneDia && ! $tieneHora) {
                $direccion[] = $linea;

                continue;
            }

            // "Lunes a viernes" + "7 a. m.–3 p. m." en líneas separadas son un
            // solo tramo: la hora se pega al día que la precede.
            $anterior = array_key_last($horarios);

            if ($tieneHora && ! $tieneDia && $anterior !== null && $this->soloDia($horarios[$anterior])) {
                $horarios[$anterior] .= ' '.$linea;
            } else {
                $horarios[] = $linea;
            }
        }

        return [
            'direccion' => $direccion ? implode(' ', $direccion) : null,
            'horarios'  => $horarios ? implode(' · ', $horarios) : null,
            'mapa_url'  => $mapaUrl,
        ];
    }

    protected function soloDia(string $tramo): bool
    {
        return preg_match(self::DIA, $tramo) && ! preg_match(self::HORA, $tramo);
    }

    /**
     * Solo https y solo los dominios de Google Maps. Se compara el host
     * completo para que "maps.app.goo.gl.otro.com" no pase.
     */
    protected function esGoogleMaps(string $url): bool
    {
        $partes = parse_url($url);

        if (($partes['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = strtolower($partes['host'] ?? '');
        $ruta = $partes['path'] ?? '';

        return match ($host) {
            'maps.app.goo.gl', 'maps.google.com' => true,
            'goo.gl'                             => str_starts_with($ruta, '/maps/'),
            'google.com', 'www.google.com'       => $ruta === '/maps' || str_starts_with($ruta, '/maps/'),
            default                              => false,
        };
    }

    /**
     * Colapsa espacios repetidos (el equipo deja dobles espacios y espacios al final).
     */
    protected function limpiar(string $linea): string
    {
        return trim(preg_replace('/\s+/u', ' ', $linea));
    }
}
