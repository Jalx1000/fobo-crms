<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Las plantillas aprobadas (HSM) de la cuenta de WhatsApp Business.
 *
 * Una plantilla es lo unico que WhatsApp acepta una vez cerrada la ventana de
 * 24h, asi que el inbox necesita saber cuales existen y cuantas variables toma
 * cada una. Meta es la fuente de verdad: no se copia nada a la base, que solo
 * seria una segunda version que mantener sincronizada.
 *
 * Solo Cloud API. Messenger no tiene plantillas, y Kommo llega a las suyas por
 * un salesbot por plantilla, asi que no hay catalogo que leer.
 */
class TemplateCatalog
{
    /**
     * Las aprobadas, normalizadas.
     *
     * @return array<int, array>
     */
    public function all(bool $fresh = false): array
    {
        $ttl = (int) config('whatsapp.templates.cache_minutes', 30);

        if ($fresh) {
            Cache::forget($this->cacheKey());
        }

        return Cache::remember($this->cacheKey(), now()->addMinutes($ttl), fn () => $this->fetch());
    }

    /**
     * Una plantilla por nombre (y por idioma, cuando varias comparten nombre).
     */
    public function find(string $name, ?string $language = null): ?array
    {
        foreach ($this->all() as $template) {
            if ($template['name'] !== $name) {
                continue;
            }

            if ($language === null || $template['language'] === $language) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Preguntarle a Meta y quedarse solo con lo que se puede mandar.
     */
    protected function fetch(): array
    {
        $config = (array) config('whatsapp.gateways.cloud_api');

        foreach (['waba_id', 'token'] as $key) {
            if (empty($config[$key])) {
                // El mensaje nombra la variable de entorno a propósito: quien lo
                // lee en el inbox no tiene el config a mano.
                throw new RuntimeException(
                    "Falta [{$key}] en gateways.cloud_api: no se puede leer el catálogo de plantillas. "
                    .'Corré `php artisan whatsapp:waba-id` y cargá WHATSAPP_WABA_ID.'
                );
            }
        }

        $response = Http::withToken($config['token'])
            ->acceptJson()
            ->get(sprintf(
                'https://graph.facebook.com/%s/%s/message_templates',
                $config['api_version'] ?? 'v21.0',
                $config['waba_id'],
            ), ['limit' => 200]);

        $response->throw();

        $templates = [];

        foreach ($response->json('data') ?? [] as $raw) {
            // Cualquier otra sería rechazada al enviarse, así que no debe llegar
            // nunca a la lista del asesor.
            if (($raw['status'] ?? '') !== 'APPROVED') {
                continue;
            }

            $templates[] = $this->normalize($raw);
        }

        usort($templates, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $templates;
    }

    /**
     * Aplanar la lista de componentes de Meta a lo que necesita el composer.
     */
    protected function normalize(array $raw): array
    {
        $header = $body = $footer = null;
        $headerFormat = null;
        $buttons = [];
        $examples = [];

        foreach ($raw['components'] ?? [] as $component) {
            switch ($component['type'] ?? '') {
                case 'HEADER':
                    $headerFormat = $component['format'] ?? 'TEXT';

                    // Solo un header de texto se puede previsualizar. El formato
                    // se conserva igual porque es lo que distingue a las
                    // plantillas capaces de llevar un PDF adjunto.
                    $header = $headerFormat === 'TEXT'
                        ? ($component['text'] ?? null)
                        : null;
                    break;

                case 'BODY':
                    $body = $component['text'] ?? '';
                    $examples = $component['example']['body_text'][0] ?? [];
                    break;

                case 'FOOTER':
                    $footer = $component['text'] ?? null;
                    break;

                case 'BUTTONS':
                    // Se muestran para que el asesor sepa qué va a ver el
                    // cliente. Las respuestas rápidas fijas no llevan parámetros.
                    $buttons = array_map(
                        fn ($b) => $b['text'] ?? '',
                        $component['buttons'] ?? [],
                    );
                    break;
            }
        }

        return [
            'id'            => $raw['id'] ?? null,
            'name'          => $raw['name'] ?? '',
            'language'      => $raw['language'] ?? '',
            'category'      => $raw['category'] ?? null,
            'header'        => $header,
            'header_format' => $headerFormat,
            // Un header multimedia es un parámetro obligatorio: mandar la
            // plantilla sin él devuelve 132012 ("parameter format does not
            // match"), que no dice nada sobre lo que falta. El composer no tiene
            // de dónde sacar ese archivo, así que necesita poder distinguirlas.
            'requires_media_header' => in_array($headerFormat, ['IMAGE', 'DOCUMENT', 'VIDEO', 'LOCATION'], true),
            // Un header de TEXTO también puede llevar placeholders, y son un
            // componente aparte del cuerpo.
            'header_variables'      => $this->countVariables((string) $header),
            'body'                  => $body ?? '',
            'footer'                => $footer,
            'buttons'               => $buttons,
            'variables'             => $this->countVariables($body ?? ''),
            'examples'              => array_values($examples),
        ];
    }

    /**
     * Cuántos parámetros espera el cuerpo.
     *
     * Se cuenta el placeholder MÁS ALTO y no cuántos aparecen: Meta los completa
     * por posición, así que un cuerpo que usa {{1}} y {{3}} necesita igual tres
     * valores o el envío se rechaza entero.
     */
    protected function countVariables(string $body): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);

        return $matches[1] ? (int) max($matches[1]) : 0;
    }

    /**
     * Con clave por cuenta, para que cambiar de número nunca sirva el catálogo
     * del número anterior.
     */
    protected function cacheKey(): string
    {
        return 'whatsapp:templates:'.(string) config('whatsapp.gateways.cloud_api.waba_id');
    }
}
