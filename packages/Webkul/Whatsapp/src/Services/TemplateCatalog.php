<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The approved templates, read from Meta.
 *
 * Cached because the list barely changes and the inbox asks for it every time a
 * conversation is opened. Refresh on demand with `php artisan whatsapp:templates --fresh`.
 *
 * Only APPROVED templates are exposed. Meta rejects a send against anything
 * else, so offering a PENDING one in the composer would just be a button that
 * always fails.
 */
class TemplateCatalog
{
    protected const CACHE_KEY = 'whatsapp.templates';

    /**
     * @return array<int, array{name:string, language:string, body:string, variables:int, category:?string}>
     */
    public function all(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $minutes = (int) config('whatsapp.templates.cache_minutes', 30);

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(max(1, $minutes)), fn () => $this->fetch());
    }

    /**
     * One template by name and language.
     *
     * Both are part of the identity: the same name exists once per language,
     * and sending the Spanish body under the English code makes Meta reject
     * the whole message.
     */
    public function find(string $name, string $language): ?array
    {
        foreach ($this->all() as $template) {
            if ($template['name'] === $name && $template['language'] === $language) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Fill a template body with its variables, for the preview and for the
     * `body` column — what the thread and the conversation list show.
     *
     * @param  array<int, string>  $variables
     */
    public function render(string $body, array $variables): string
    {
        $variables = array_values($variables);

        foreach ($variables as $i => $value) {
            $body = str_replace('{{'.($i + 1).'}}', (string) $value, $body);
        }

        return $body;
    }

    /**
     * @return array<int, array>
     */
    protected function fetch(): array
    {
        $config = config('whatsapp.gateways.cloud_api', []);

        $wabaId = $config['waba_id'] ?? null;
        $token = $config['token'] ?? null;

        if (! $wabaId || ! $token) {
            throw new RuntimeException(
                'Faltan WHATSAPP_WABA_ID o WHATSAPP_TOKEN: sin eso no se puede leer el catálogo de plantillas.'
            );
        }

        $version = (string) ($config['api_version'] ?? 'v21.0');

        $response = Http::withToken($token)
            ->acceptJson()
            ->get("https://graph.facebook.com/{$version}/{$wabaId}/message_templates", ['limit' => 100]);

        $response->throw();

        return collect($response->json('data', []))
            ->filter(fn ($t) => ($t['status'] ?? null) === 'APPROVED')
            ->map(function (array $t) {
                $body = collect($t['components'] ?? [])
                    ->firstWhere('type', 'BODY')['text'] ?? '';

                return [
                    'name'      => (string) $t['name'],
                    'language'  => (string) $t['language'],
                    'body'      => $body,
                    'variables' => $this->countVariables($body),
                    'category'  => $t['category'] ?? null,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * How many distinct placeholders the body has.
     *
     * Distinct, not total: a body that says "Hola {{1}}, tu pedido {{2}} — {{1}},
     * te esperamos" needs TWO values, not three. Counting occurrences would ask
     * the advisor for a variable that does not exist and then fail Meta's own
     * validation.
     */
    protected function countVariables(string $body): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);

        return count(array_unique($matches[1] ?? []));
    }
}
