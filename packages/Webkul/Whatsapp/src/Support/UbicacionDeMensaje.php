<?php

namespace Webkul\Whatsapp\Support;

/**
 * Las coordenadas de un mensaje de tipo `location`, en un solo lugar.
 *
 * Lee LAS DOS formas del `payload`: `['location' => …]` para lo que mandamos
 * nosotros y el nodo del proveedor para lo que llegó. Antes esto estaba
 * duplicado en tres lados —el hilo del inbox, el Punto y el payload del
 * agente— y cada copia decidía por su cuenta qué hacer con un nodo incompleto.
 */
class UbicacionDeMensaje
{
    /**
     * @return array{lat: float, lng: float, name: ?string, address: ?string, url: string}|null
     */
    public static function de(?array $payload): ?array
    {
        $node = $payload['location'] ?? null;

        if (! is_array($node) || ! isset($node['latitude'], $node['longitude'])) {
            return null;
        }

        $lat = (float) $node['latitude'];
        $lng = (float) $node['longitude'];

        // Un pin en 0,0 es el Golfo de Guinea: es el valor que deja un campo
        // vacío, no una ubicación que alguien haya compartido.
        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        return [
            'lat'     => $lat,
            'lng'     => $lng,
            'name'    => $node['name'] ?? null,
            'address' => $node['address'] ?? null,
            'url'     => sprintf('https://www.google.com/maps?q=%s,%s', $lat, $lng),
        ];
    }

    /**
     * El mismo pin como texto, en el formato que el agente tiene documentado
     * para `ubicacion_lead`: "<lat>,<lng> | <referencia>".
     *
     * 🔴 Sin prefijo ni palabras adelante: el agente reenvía este texto tal cual
     * al campo del lead, y cualquier adorno termina guardado en el CRM.
     *
     * Seis decimales: ~11 cm. Más precisión que eso es ruido del GPS del
     * teléfono, y menos no alcanza para distinguir una casa de la de al lado.
     */
    public static function texto(?array $payload): ?string
    {
        $u = static::de($payload);

        if (! $u) {
            return null;
        }

        $texto = sprintf('%.6f,%.6f', $u['lat'], $u['lng']);

        $referencia = trim(implode(', ', array_filter([$u['name'], $u['address']])));

        return $referencia === '' ? $texto : $texto.' | '.$referencia;
    }
}
