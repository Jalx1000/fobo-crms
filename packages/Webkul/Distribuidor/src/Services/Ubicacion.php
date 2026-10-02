<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;

/**
 * Dónde hay que entregar un pedido.
 *
 * Hay tres fuentes posibles y ninguna sola alcanza, así que se consultan en orden
 * de confianza:
 *
 *   1. El pin que el cliente mandó por WhatsApp. Es el más fiable: lat/lng
 *      estructurados, puestos por el propio cliente desde su teléfono.
 *   2. Coordenadas en el atributo `ubicacion_lead` del pedido.
 *   3. El texto libre de ese mismo atributo, que no ubica en un mapa pero es lo
 *      que de verdad usa quien toca el timbre: "Portón verde y hay un árbol
 *      afuera" vale más que un pin a 20 metros.
 *
 * Las dos primeras dan un punto en el mapa; la tercera siempre se devuelve además,
 * porque se complementan.
 */
class Ubicacion
{
    protected const ATRIBUTO = 'ubicacion_lead';

    /**
     * @return array{referencia:?string, lat:?float, lng:?float, url:?string, fuente:?string}
     */
    public function dePedido(int $leadId): array
    {
        $texto = $this->textoDe($leadId);

        // 1. El pin de WhatsApp gana: lo puso el cliente, no un parseo nuestro.
        if ($pin = $this->pinDeWhatsapp($leadId)) {
            return $this->resultado($texto, $pin['lat'], $pin['lng'], 'whatsapp');
        }

        // 2. Coordenadas dentro del texto del atributo.
        if ($coords = $this->coordenadasEn($texto)) {
            return $this->resultado($texto, $coords[0], $coords[1], 'pedido');
        }

        // 3. Sólo la referencia escrita.
        return $this->resultado($texto, null, null, $texto ? 'referencia' : null);
    }

    /**
     * El último pin que el cliente del pedido mandó por WhatsApp.
     */
    protected function pinDeWhatsapp(int $leadId): ?array
    {
        $personId = DB::table('leads')->where('id', $leadId)->value('person_id');

        if (! $personId) {
            return null;
        }

        $conversaciones = DB::table('whatsapp_conversations')
            ->where('person_id', $personId)
            ->orWhere('lead_id', $leadId)
            ->pluck('id');

        if ($conversaciones->isEmpty()) {
            return null;
        }

        $mensaje = DB::table('whatsapp_messages')
            ->whereIn('conversation_id', $conversaciones)
            ->where('type', 'location')
            ->where('direction', 'inbound')
            ->orderByDesc('id')
            ->first();

        if (! $mensaje) {
            return null;
        }

        $payload = json_decode((string) $mensaje->payload, true);
        $node = $payload['location'] ?? null;

        if (! is_array($node) || ! isset($node['latitude'], $node['longitude'])) {
            return null;
        }

        return $this->validar((float) $node['latitude'], (float) $node['longitude']);
    }

    /**
     * Saca coordenadas de un texto libre.
     *
     * Dos formatos, y el segundo existe por un bug real: n8n escribe este campo y
     * guardó "latitude17754821longitude631973421" — el punto decimal y el signo
     * menos se perdieron y las etiquetas quedaron pegadas al número. Así no se
     * abre en ningún mapa.
     *
     * Para esos casos se reconstruye asumiendo DOS dígitos de parte entera y signo
     * negativo, que es lo que corresponde a toda Argentina y Bolivia. Es una
     * heurística regional, no una verdad universal, y por eso el resultado pasa
     * igual por `validar()`: si no cae en un rango plausible se devuelve null.
     *
     * Un pin equivocado manda al distribuidor a otra dirección, que es peor que no
     * tener pin.
     *
     * @return array{0:float, 1:float}|null
     */
    public function coordenadasEn(?string $texto): ?array
    {
        if (! $texto) {
            return null;
        }

        // Formato sano: "-24.193000,-65.297000" o "lat: -24.19 lng: -65.29".
        if (preg_match('/(-?\d{1,2}\.\d{3,})\s*[,;\s]\s*(-?\d{1,3}\.\d{3,})/', $texto, $m)) {
            if ($ok = $this->validar((float) $m[1], (float) $m[2])) {
                return [$ok['lat'], $ok['lng']];
            }
        }

        // Formato roto de n8n: dígitos pegados a las etiquetas, sin punto ni signo.
        if (preg_match('/latitude\s*:?\s*(\d{6,12}).*?longitude\s*:?\s*(\d{6,12})/i', $texto, $m)) {
            $lat = $this->reconstruir($m[1]);
            $lng = $this->reconstruir($m[2]);

            if ($lat !== null && $lng !== null && ($ok = $this->validar($lat, $lng))) {
                return [$ok['lat'], $ok['lng']];
            }
        }

        return null;
    }

    /**
     * "17754821" => -17.754821. Dos dígitos de parte entera, signo negativo.
     */
    protected function reconstruir(string $digitos): ?float
    {
        if (strlen($digitos) < 4) {
            return null;
        }

        return -1 * (float) (substr($digitos, 0, 2).'.'.substr($digitos, 2));
    }

    /**
     * Sólo coordenadas dentro de la zona de reparto (Jujuy).
     *
     * Acotar a la provincia y no al país entero es lo que hace confiable la
     * reconstrucción de coordenadas mal formadas: con un recuadro chico, un parseo
     * que salió mal cae afuera y se descarta. Con el país entero, pasaría.
     *
     * Devolver null es lo correcto cuando no se puede confiar: el distribuidor se
     * guía por la referencia escrita, que es lo que usa igual para tocar el timbre.
     */
    protected function validar(float $lat, float $lng): ?array
    {
        $zona = (array) config('distribuidor.zona');

        $dentro = $lat >= $zona['lat_min'] && $lat <= $zona['lat_max']
            && $lng >= $zona['lng_min'] && $lng <= $zona['lng_max'];

        return $dentro ? ['lat' => $lat, 'lng' => $lng] : null;
    }

    protected function textoDe(int $leadId): ?string
    {
        $attributeId = DB::table('attributes')->where('code', self::ATRIBUTO)->value('id');

        if (! $attributeId) {
            return null;
        }

        $valor = DB::table('attribute_values')
            ->where('attribute_id', $attributeId)
            ->where('entity_type', 'leads')
            ->where('entity_id', $leadId)
            ->value('text_value');

        $valor = trim((string) $valor);

        return $valor !== '' ? $valor : null;
    }

    /**
     * La referencia que se le muestra al distribuidor: el texto SIN las
     * coordenadas, que para un humano son ruido.
     */
    protected function referenciaLimpia(?string $texto): ?string
    {
        if (! $texto) {
            return null;
        }

        $limpio = preg_replace([
            '/latitude\s*:?\s*-?[\d.]+/i',
            '/longitude\s*:?\s*-?[\d.]+/i',
            '/-?\d{1,2}\.\d{3,}\s*[,;]\s*-?\d{1,3}\.\d{3,}/',
        ], '', $texto);

        $limpio = trim(preg_replace('/\s*\|\s*$|^\s*\|\s*/', '', trim($limpio)), " \t|,;");

        return $limpio !== '' ? $limpio : null;
    }

    protected function resultado(?string $texto, ?float $lat, ?float $lng, ?string $fuente): array
    {
        $referencia = $this->referenciaLimpia($texto);

        return [
            'referencia' => $referencia,
            'lat'        => $lat,
            'lng'        => $lng,
            'url'        => $this->url($referencia, $lat, $lng),
            // 'pin' = punto exacto · 'busqueda' = lo resuelve el mapa del dispositivo
            'modo'       => ($lat !== null && $lng !== null) ? 'pin' : ($referencia ? 'busqueda' : null),
            'fuente'     => $fuente,
        ];
    }

    /**
     * El enlace al mapa. Dos modos, según lo que haya.
     *
     * Con coordenadas, un pin exacto. Con sólo una dirección escrita, una BÚSQUEDA
     * con la ciudad y el país agregados, y la resuelve el mapa del dispositivo.
     *
     * No geocodificamos nosotros a propósito: una API de geocodificación trae
     * clave, costo, límites de uso y, lo peor, el riesgo de guardar un pin
     * equivocado en silencio. Delegando la búsqueda, el geocodificador de Google
     * hace el trabajo —es mejor que cualquiera que escribamos— y si la dirección
     * es ambigua el distribuidor VE la ambigüedad y la corrige con el dedo, en vez
     * de que nosotros elijamos mal por él.
     *
     * El sufijo no es opcional: "calle 6 nro 234" matchea en cualquier ciudad del
     * país y abriría el mapa en el lugar equivocado.
     */
    protected function url(?string $referencia, ?float $lat, ?float $lng): ?string
    {
        if ($lat !== null && $lng !== null) {
            return sprintf('https://www.google.com/maps/search/?api=1&query=%s,%s', $lat, $lng);
        }

        if (! $referencia) {
            return null;
        }

        $sufijo = (string) config('distribuidor.zona.sufijo_busqueda');

        return 'https://www.google.com/maps/search/?api=1&query='
            .rawurlencode(trim($referencia.', '.$sufijo, ' ,'));
    }
}
