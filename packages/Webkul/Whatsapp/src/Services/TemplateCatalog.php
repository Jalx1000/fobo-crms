<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The approved message templates of the WhatsApp Business Account.
 *
 * Templates are the only thing that can be sent once the 24h window closes, so
 * the inbox needs to know which ones exist and how many variables each one
 * takes. Meta is the source of truth: nothing is copied into the database,
 * which would only create a second version to keep in sync.
 *
 * Cloud API only. Kommo reaches its templates through a salesbot per template,
 * so it has no catalogue to read.
 */
class TemplateCatalog
{
    /**
     * Approved templates, normalized. Cached because the catalogue barely
     * changes and the inbox asks for it on every conversation.
     *
     * @return array<int, array>
     */
    public function all(bool $fresh = false): array
    {
        $ttl = (int) config('whatsapp.templates.cache_minutes', 30);

        if ($fresh) {
            Cache::forget(self::cacheKey());
        }

        return Cache::remember(self::cacheKey(), now()->addMinutes($ttl), fn () => $this->fetch());
    }

    /**
     * One template by name (and language, when several share a name).
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
     * Ask Meta and keep only what can actually be sent.
     */
    protected function fetch(): array
    {
        $config = config('whatsapp.gateways.cloud_api');

        foreach (['waba_id', 'token'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException(
                    "WhatsApp: missing [{$key}] in gateways.cloud_api; the template catalogue cannot be read."
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
            // Anything not approved would be rejected on send, so it must never
            // reach the agent's list.
            if (($raw['status'] ?? '') !== 'APPROVED') {
                continue;
            }

            $templates[] = $this->normalize($raw);
        }

        usort($templates, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $templates;
    }

    /**
     * Flatten Meta's component list into what the composer needs.
     */
    protected function normalize(array $raw): array
    {
        $header = $body = $footer = null;
        $buttons = [];
        $examples = [];

        foreach ($raw['components'] ?? [] as $component) {
            switch ($component['type'] ?? '') {
                case 'HEADER':
                    // Only a text header can be rendered as a preview; a media
                    // header would need its own parameter, which is out of scope.
                    $header = ($component['format'] ?? 'TEXT') === 'TEXT'
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
                    // Shown so the agent knows what the customer will see. Fixed
                    // quick replies need no parameters when sending.
                    $buttons = array_map(
                        fn ($b) => $b['text'] ?? '',
                        $component['buttons'] ?? [],
                    );
                    break;
            }
        }

        return [
            'id'        => $raw['id'] ?? null,
            'name'      => $raw['name'] ?? '',
            'language'  => $raw['language'] ?? '',
            'category'  => $raw['category'] ?? null,
            'header'    => $header,
            'body'      => $body ?? '',
            'footer'    => $footer,
            'buttons'   => $buttons,
            'variables' => $this->countVariables($body ?? ''),
            'examples'  => array_values($examples),
        ];
    }

    /**
     * How many parameters the body expects.
     *
     * Counted as the HIGHEST placeholder rather than how many appear: Meta fills
     * them positionally, so a body using {{1}} and {{3}} still needs three
     * values or the send is rejected.
     */
    protected function countVariables(string $body): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);

        return $matches[1] ? (int) max($matches[1]) : 0;
    }

    /**
     * Keyed by account so switching numbers never serves the previous catalogue.
     */
    protected static function cacheKey(): string
    {
        return 'whatsapp:templates:'.(string) config('whatsapp.gateways.cloud_api.waba_id');
    }
}
