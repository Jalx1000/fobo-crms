<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Creates the message templates of config('whatsapp-templates') in the
 * WhatsApp Business Account.
 *
 * Meta rejects a template for format reasons only after a round trip (and
 * sometimes after review), so the same rules are checked here first. Creating
 * is never an update: a name that already exists is reported, not touched.
 */
class TemplatePublisher
{
    public const CATEGORIES = ['MARKETING', 'UTILITY'];

    /**
     * Format problems Meta would reject the template for. Empty = valid.
     *
     * @return array<int, string>
     */
    public function validate(string $name, array $definition): array
    {
        $errors = [];
        $body = (string) ($definition['body'] ?? '');

        if (! preg_match('/^[a-z0-9_]{1,512}$/', $name)) {
            $errors[] = 'El nombre solo admite minúsculas, números y guion bajo.';
        }

        if (! in_array($definition['category'] ?? null, self::CATEGORIES, true)) {
            $errors[] = 'La categoría debe ser '.implode(' o ', self::CATEGORIES).'.';
        }

        if (empty($definition['language'])) {
            $errors[] = 'Falta el idioma (p. ej. «es»).';
        }

        if ($body === '' || mb_strlen($body) > 1024) {
            $errors[] = 'El cuerpo es obligatorio y tiene un máximo de 1024 caracteres.';
        }

        preg_match_all('/\{\{(\d+)\}\}/', $body, $matches);
        $numbers = array_map('intval', $matches[1]);

        // Meta numera en orden y sin saltos: {{1}}, {{2}}...
        if ($numbers && array_values(array_unique($numbers)) !== range(1, count(array_unique($numbers)))) {
            $errors[] = 'Las variables deben ir en orden y sin saltos: {{1}}, {{2}}...';
        }

        if (preg_match('/^\s*\{\{\d+\}\}|\{\{\d+\}\}[\s.!?]*$/u', $body)) {
            $errors[] = 'El cuerpo no puede empezar ni terminar con una variable.';
        }

        if (preg_match('/\{\{\d+\}\}\s*\{\{\d+\}\}/', $body)) {
            $errors[] = 'Dos variables no pueden ir seguidas.';
        }

        $variables = $numbers ? max($numbers) : 0;
        $examples = $definition['examples'] ?? [];

        if (count($examples) !== $variables) {
            $errors[] = "El cuerpo usa {$variables} variable(s) y hay ".count($examples).' ejemplo(s).';
        }

        foreach (['header' => 60, 'footer' => 60] as $part => $max) {
            $text = $definition[$part] ?? null;

            if ($text === null) {
                continue;
            }

            if (mb_strlen($text) > $max || str_contains($text, "\n") || str_contains($text, '{{')) {
                $errors[] = "El {$part} es una sola línea, sin variables y de máximo {$max} caracteres.";
            }
        }

        $buttons = $definition['buttons'] ?? [];

        if (count($buttons) > 10) {
            $errors[] = 'Máximo 10 botones.';
        }

        foreach ($buttons as $button) {
            if ($button === '' || mb_strlen($button) > 25) {
                $errors[] = "El botón «{$button}» debe tener entre 1 y 25 caracteres.";
            }
        }

        if (count($buttons) !== count(array_unique($buttons))) {
            $errors[] = 'Hay botones repetidos.';
        }

        return $errors;
    }

    /**
     * The body Meta expects on POST /{waba}/message_templates.
     */
    public function payload(string $name, array $definition): array
    {
        $components = [];

        if (! empty($definition['header'])) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $definition['header']];
        }

        $body = ['type' => 'BODY', 'text' => $definition['body']];

        if (! empty($definition['examples'])) {
            $body['example'] = ['body_text' => [array_values($definition['examples'])]];
        }

        $components[] = $body;

        if (! empty($definition['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $definition['footer']];
        }

        if (! empty($definition['buttons'])) {
            $components[] = [
                'type'    => 'BUTTONS',
                'buttons' => array_map(
                    fn ($text) => ['type' => 'QUICK_REPLY', 'text' => $text],
                    array_values($definition['buttons']),
                ),
            ];
        }

        return [
            'name'             => $name,
            'language'         => $definition['language'],
            'category'         => $definition['category'],
            'parameter_format' => 'positional',
            'components'       => $components,
        ];
    }

    /**
     * Every template in the account, whatever its status, keyed by name and
     * language ("saludo|es").
     *
     * @return array<string, array{status: string, reason: ?string}>
     */
    public function existing(): array
    {
        $existing = [];
        $url = $this->url();
        $query = ['limit' => 200, 'fields' => 'name,language,status,rejected_reason'];

        // Paginated: a big account does not fit in one page.
        while ($url) {
            $response = $this->http()->get($url, $query)->throw();

            foreach ($response->json('data') ?? [] as $raw) {
                $existing[($raw['name'] ?? '').'|'.($raw['language'] ?? '')] = [
                    'status' => $raw['status'] ?? 'UNKNOWN',
                    'reason' => ($raw['rejected_reason'] ?? 'NONE') === 'NONE' ? null : $raw['rejected_reason'],
                ];
            }

            $url = $response->json('paging.next');
            $query = []; // the next link already carries them
        }

        return $existing;
    }

    /**
     * Create one template. Returns Meta's status for it (usually PENDING).
     */
    public function create(string $name, array $definition): string
    {
        $response = $this->http()->post($this->url(), $this->payload($name, $definition));

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('error.error_user_msg')
                    ?? $response->json('error.message')
                    ?? 'Meta respondió '.$response->status().'.'
            );
        }

        $this->forgetCatalogue();

        return (string) ($response->json('status') ?? 'PENDING');
    }

    /**
     * Delete a template by name (every language of it). Meta does not let the
     * name be reused for a while afterwards, so this is for templates that are
     * not ours (hello_world), never a way to "edit" one.
     */
    public function delete(string $name): void
    {
        $response = $this->http()->delete($this->url().'?'.http_build_query(['name' => $name]));

        if ($response->failed() || $response->json('success') === false) {
            throw new RuntimeException(
                $response->json('error.error_user_msg')
                    ?? $response->json('error.message')
                    ?? 'Meta respondió '.$response->status().'.'
            );
        }

        $this->forgetCatalogue();
    }

    protected function forgetCatalogue(): void
    {
        // The chat reads the catalogue from cache; a change should show up
        // without waiting for it to expire. Best effort: the change already
        // happened in Meta, and a cache that is unreachable from where the
        // command runs (no Redis on the host) must not report it as failed.
        try {
            Cache::forget('whatsapp:templates:'.(string) config('whatsapp.gateways.cloud_api.waba_id'));
        } catch (Throwable) {
            // The cache expires on its own (whatsapp.templates.cache_minutes).
        }
    }

    protected function url(): string
    {
        $config = config('whatsapp.gateways.cloud_api');

        foreach (['waba_id', 'token'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException("Falta [{$key}] en whatsapp.gateways.cloud_api: no se pueden crear plantillas.");
            }
        }

        return sprintf(
            'https://graph.facebook.com/%s/%s/message_templates',
            $config['api_version'] ?? 'v21.0',
            $config['waba_id'],
        );
    }

    protected function http(): PendingRequest
    {
        return Http::withToken((string) config('whatsapp.gateways.cloud_api.token'))->acceptJson();
    }
}
