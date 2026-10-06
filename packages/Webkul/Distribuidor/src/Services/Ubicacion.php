<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Distribuidor\Models\UbicacionCliente;
use Webkul\Distribuidor\Models\UbicacionClienteProxy;

/**
 * Dónde hay que entregar un pedido.
 *
 * Hay tres fuentes posibles y ninguna sola alcanza, así que se consultan en orden
 * de confianza:
 *
 *   0. El GPS guardado del cliente, si alguien lo tomó en la puerta. Le gana a
 *      todo: el pin de WhatsApp lo manda el cliente desde donde está en ese
 *      momento —que puede ser el trabajo— mientras el GPS se toma en el domicilio
 *      al entregar.
 *   1. El pin que el cliente mandó por WhatsApp. Estructurado y puesto por el
 *      propio cliente desde su teléfono.
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
        $personId = (int) DB::table('leads')->where('id', $leadId)->value('person_id');

        // 0. El domicilio guardado del cliente, si se tomó con GPS en la puerta.
        $guardada = $this->deCliente($personId);

        if ($guardada && in_array($guardada['fuente'], UbicacionCliente::fuentesHumanas(), true)) {
            return $this->resultado(
                $texto,
                $guardada['lat'],
                $guardada['lng'],
                $guardada['fuente'] === UbicacionCliente::FUENTE_GPS ? 'cliente_gps' : 'cliente_mapa',
                $guardada,
            );
        }

        // 1. El pin de WhatsApp. De paso queda guardado en la ficha del cliente:
        // la próxima vez hay domicilio aunque este pedido no traiga pin.
        if ($pin = $this->pinDeWhatsapp($leadId)) {
            if ($personId) {
                $this->recordarPin($personId, $pin['lat'], $pin['lng']);
            }

            return $this->resultado($texto, $pin['lat'], $pin['lng'], 'whatsapp', $guardada);
        }

        // 1b. Un pin de WhatsApp de otro pedido, ya guardado en la ficha.
        if ($guardada) {
            return $this->resultado($texto, $guardada['lat'], $guardada['lng'], 'cliente_whatsapp', $guardada);
        }

        // 2. Coordenadas dentro del texto del atributo.
        if ($coords = $this->coordenadasEn($texto)) {
            return $this->resultado($texto, $coords[0], $coords[1], 'pedido', $guardada);
        }

        // 3. Sólo la referencia escrita.
        return $this->resultado($texto, null, null, $texto ? 'referencia' : null, $guardada);
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

    /**
     * El texto de ubicación del pedido.
     *
     * Dos fuentes, y la segunda no es un lujo: el atributo `ubicacion_lead` lo
     * escribe n8n, pero la nota que escribe el distribuidor en el Punto va a
     * `description`. Sin este fallback, alguien tipeaba "Av. Bolivia 2335, portón
     * azul" al tomar el pedido y el bloque "Dónde entregar" quedaba vacío —
     * encontrado recorriendo el flujo a mano.
     */
    protected function textoDe(int $leadId): ?string
    {
        $attributeId = DB::table('attributes')->where('code', self::ATRIBUTO)->value('id');

        if ($attributeId) {
            $valor = trim((string) DB::table('attribute_values')
                ->where('attribute_id', $attributeId)
                ->where('entity_type', 'leads')
                ->where('entity_id', $leadId)
                ->value('text_value'));

            if ($valor !== '') {
                return $valor;
            }
        }

        $descripcion = trim((string) DB::table('leads')->where('id', $leadId)->value('description'));

        return $descripcion !== '' ? $descripcion : null;
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

    /**
     * El domicilio guardado del cliente, o null si nadie lo marcó todavía.
     *
     * @return array{lat:float, lng:float, fuente:string, url:string}|null
     */
    public function deCliente(?int $personId): ?array
    {
        if (! $personId || ! $this->tablaLista()) {
            return null;
        }

        $fila = UbicacionClienteProxy::modelClass()::query()
            ->where('person_id', $personId)
            ->first();

        if (! $fila) {
            return null;
        }

        return [
            'lat'        => (float) $fila->lat,
            'lng'        => (float) $fila->lng,
            'fuente'     => (string) $fila->fuente,
            'direccion'  => $fila->direccion,
            'referencia' => $fila->referencia,
            'url'        => $this->url(null, (float) $fila->lat, (float) $fila->lng),
        ];
    }

    /**
     * Guarda el GPS tomado en la puerta. Pisa cualquier cosa anterior.
     *
     * NO se valida contra la zona de reparto, y es deliberado: esa caja existe para
     * validar la reconstrucción de los pines de WhatsApp —que llegan mutilados y hay
     * que adivinar dónde va el signo y la coma—, no para juzgar un GPS. El GPS del
     * navegador viene bien formado, así que la caja no protegía de nada (cualquier
     * lectura de un distribuidor parado en Jujuy cae dentro igual) y en cambio
     * rechazaba coordenadas válidas tomadas desde otra provincia o país.
     *
     * Lo que sí se valida es la PRECISIÓN que informa el navegador, que es lo que de
     * verdad distingue la puerta del cliente de la antena de telefonía: el GPS
     * enganchado da 5-20 m, una ubicación por wifi o por antena da cientos.
     *
     * La precisión es opcional: si el navegador no la informa, se guarda igual. Es
     * mejor una ubicación sin medida de calidad que ninguna.
     */
    public function guardarGps(
        int $personId,
        float $lat,
        float $lng,
        ?int $userId = null,
        ?float $precision = null,
        ?array $textos = null,
    ): bool {
        if (! $this->tablaLista()) {
            return false;
        }

        if ($precision !== null && $precision > (float) config('distribuidor.zona.precision_maxima')) {
            return false;
        }

        UbicacionClienteProxy::modelClass()::updateOrCreate(
            ['person_id' => $personId],
            array_merge([
                'lat'     => $lat,
                'lng'     => $lng,
                'fuente'  => UbicacionCliente::FUENTE_GPS,
                'user_id' => $userId ?? auth()->guard('user')->id(),
            ], $this->textosAGuardar($textos)),
        );

        return true;
    }

    /**
     * Guarda un punto elegido a mano sobre el mapa.
     *
     * No se le mide precisión: la puso una persona mirando el domicilio, así que la
     * calidad la juzgó ella. Tampoco se recorta por zona, por lo mismo que el GPS —el
     * detalle está en guardarGps().
     */
    public function guardarDelMapa(
        int $personId,
        float $lat,
        float $lng,
        ?int $userId = null,
        ?array $textos = null,
    ): bool {
        if (! $this->tablaLista()) {
            return false;
        }

        UbicacionClienteProxy::modelClass()::updateOrCreate(
            ['person_id' => $personId],
            array_merge([
                'lat'     => $lat,
                'lng'     => $lng,
                'fuente'  => UbicacionCliente::FUENTE_MAPA,
                'user_id' => $userId ?? auth()->guard('user')->id(),
            ], $this->textosAGuardar($textos)),
        );

        return true;
    }

    /**
     * Qué textos del domicilio se escriben en este guardado.
     *
     * 🔴 Sólo los que LLEGAN. Un null significa "no lo mandé", no "borralo": el botón de
     * un toque del diálogo de entrega guarda las coordenadas sin textos, y si esto los
     * sobreescribiera con null, marcar el domicilio en la puerta BORRARÍA la referencia
     * que alguien escribió antes —"portón verde, hay un árbol"— sin ningún aviso.
     *
     * Para vaciar un texto a propósito se manda la cadena vacía, que sí se guarda.
     *
     * Las columnas pueden no existir todavía: el código y las migraciones se despliegan
     * por separado, y escribirlas antes de tiempo tira una excepción de columna
     * desconocida en medio del alta de un contacto.
     *
     * @param  array{direccion?:?string, referencia?:?string}|null  $textos
     * @return array<string, string>
     */
    protected function textosAGuardar(?array $textos): array
    {
        if (! $textos || ! $this->columnasDeTextoListas()) {
            return [];
        }

        $guardar = [];

        foreach (['direccion', 'referencia'] as $campo) {
            if (array_key_exists($campo, $textos) && $textos[$campo] !== null) {
                $guardar[$campo] = trim((string) $textos[$campo]);
            }
        }

        return $guardar;
    }

    protected function columnasDeTextoListas(): bool
    {
        static $listas = null;

        return $listas ??= Schema::hasColumn('distribuidor_ubicaciones', 'referencia');
    }

    /**
     * Guarda el pin de WhatsApp, pero NUNCA encima de algo puesto a mano.
     *
     * El pin es mejor que nada y peor que lo que puso una persona. Sin esta regla, un
     * cliente que manda su ubicación desde el trabajo borraría el domicilio que el
     * distribuidor marcó bien, y el error aparecería recién en el próximo reparto.
     */
    /**
     * Si la tabla de ubicaciones ya existe.
     *
     * Igual que en Bidones: entre el despliegue del código y el de la migración, el
     * Punto quedaba tirando 500. Sin la tabla no hay domicilio guardado y la
     * cascada sigue funcionando con el pin de WhatsApp y el texto, que es como
     * funcionaba antes.
     */
    protected function tablaLista(): bool
    {
        static $existe = null;

        return $existe ??= Schema::hasTable('distribuidor_ubicaciones');
    }

    public function recordarPin(int $personId, float $lat, float $lng): bool
    {
        if (! $this->tablaLista() || ! $this->validar($lat, $lng)) {
            return false;
        }

        $actual = UbicacionClienteProxy::modelClass()::query()->where('person_id', $personId)->first();

        // No pisa nada que haya puesto una persona a propósito: ni el GPS de la
        // puerta ni el punto elegido a mano en el mapa.
        if ($actual && in_array($actual->fuente, UbicacionCliente::fuentesHumanas(), true)) {
            return false;
        }

        UbicacionClienteProxy::modelClass()::updateOrCreate(
            ['person_id' => $personId],
            ['lat' => $lat, 'lng' => $lng, 'fuente' => UbicacionCliente::FUENTE_WHATSAPP],
        );

        return true;
    }

    /**
     * @param  array{direccion?:?string, referencia?:?string}|null  $delCliente
     */
    protected function resultado(
        ?string $texto,
        ?float $lat,
        ?float $lng,
        ?string $fuente,
        ?array $delCliente = null,
    ): array {
        /*
         * La referencia del CLIENTE le gana a la del pedido.
         *
         * Las dos existen porque llegaron en momentos distintos: `ubicacion_lead` es un
         * atributo por pedido que venía de antes, y la del cliente la escribe el
         * distribuidor una vez. Si gana la del pedido, cada pedido nuevo que no la traiga
         * hace desaparecer la referencia que alguien ya escribió, y nadie entiende por qué
         * un cliente conocido quedó sin indicaciones.
         *
         * La del pedido queda como respaldo y no se borra: en los pedidos viejos es el
         * único dato que hay.
         */
        $referencia = trim((string) ($delCliente['referencia'] ?? '')) ?: $this->referenciaLimpia($texto);

        return [
            'referencia' => $referencia,
            // La dirección escrita, que sólo existe a nivel cliente.
            'direccion'  => trim((string) ($delCliente['direccion'] ?? '')) ?: null,
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
