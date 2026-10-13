<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * De una coordenada a una dirección escrita.
 *
 * El negocio pidió que la dirección salga del pin del mapa en vez de tipearse. Eso es
 * geocodificación inversa, y el módulo la evitaba a propósito: el comentario de
 * Ubicacion::url() explica por qué —clave, costo, límites de uso y, lo peor, el riesgo de
 * guardar una dirección equivocada en silencio.
 *
 * Ese riesgo no desaparece al invertir la dirección, así que la regla es una: lo que
 * devuelve esto se PRECARGA EDITABLE y nunca se guarda a ciegas. El distribuidor ve lo
 * que resolvió y lo corrige. Sin eso, una altura inventada por el geocodificador queda
 * guardada y nadie la cuestiona nunca.
 *
 * Nominatim y no Google: sin clave ni facturación, y el volumen del Punto es una consulta
 * por contacto nuevo. Si las alturas salen flojas en la práctica, cambiar de proveedor es
 * cambiar esta clase.
 */
class Geocodificador
{
    /**
     * La dirección de esa coordenada, o null si no se pudo resolver.
     *
     * Null es un resultado normal, no un error: hay coordenadas en medio del campo. Quien
     * llama deja el campo vacío y el pin se guarda igual.
     */
    public function direccionDe(float $lat, float $lng): ?string
    {
        $config = (array) config('distribuidor.geocodificacion');

        if (! ($config['enabled'] ?? true)) {
            return null;
        }

        try {
            $r = Http::timeout((int) ($config['timeout'] ?? 5))
                /*
                 * Nominatim EXIGE identificar la aplicación: sin User-Agent propio
                 * devuelve 403, y su política de uso lo pide explícitamente. Con el
                 * dominio del CRM, para que puedan avisarnos antes de bloquear.
                 */
                ->withHeaders(['User-Agent' => (string) ($config['user_agent'] ?? 'SensiaCRM')])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat'            => $lat,
                    'lon'            => $lng,
                    'format'         => 'jsonv2',
                    // 18 = nivel de edificio. Más grueso devuelve el barrio, que no sirve
                    // para tocar un timbre.
                    'zoom'            => 18,
                    'addressdetails'  => 1,
                    'accept-language' => 'es',
                ]);

            if ($r->failed()) {
                Log::info('Geocodificación inversa sin respuesta', ['status' => $r->status()]);

                return null;
            }

            return $this->armar((array) $r->json('address'));
        } catch (\Throwable $e) {
            /*
             * Se traga la excepción a propósito y se registra en info, no en error: que el
             * geocodificador no responda no es un problema del Punto. El pin se guarda
             * igual y el distribuidor escribe la dirección a mano, que es lo que hacía
             * antes de que esto existiera.
             */
            Log::info('Geocodificación inversa falló', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * La dirección en el orden en que se lee en Argentina: calle, altura, barrio.
     *
     * Se arma de los campos sueltos y no se usa `display_name`, que viene con provincia,
     * país y código postal: "Avenida San Martín 1234, Alto Comedero, San Salvador de
     * Jujuy, Jujuy, Y4600, Argentina" no es una dirección, es un párrafo.
     *
     * @param  array<string, string>  $a
     */
    protected function armar(array $a): ?string
    {
        $calle = $a['road'] ?? $a['pedestrian'] ?? $a['residential'] ?? null;

        if (! $calle) {
            return null;
        }

        $partes = [trim($calle.' '.($a['house_number'] ?? ''))];

        // El barrio ayuda a ubicar en ciudades donde la calle se repite.
        $barrio = $a['neighbourhood'] ?? $a['suburb'] ?? $a['quarter'] ?? null;

        if ($barrio) {
            $partes[] = $barrio;
        }

        return implode(', ', $partes);
    }
}
