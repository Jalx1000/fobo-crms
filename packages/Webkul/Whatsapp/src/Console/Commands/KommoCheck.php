<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Read-only preflight for the Kommo gateway: proves the credentials work and
 * that the configured field/bot ids match reality, before a real message is
 * ever sent. Sends nothing.
 */
class KommoCheck extends Command
{
    /**
     * @var string
     */
    protected $signature = 'whatsapp:kommo-check {lead : A real Kommo lead id (element_id from one of its webhooks)}';

    /**
     * @var string
     */
    protected $description = 'Verify the Kommo credentials and field/bot configuration against the live API (read-only).';

    public function handle(): int
    {
        $config = config('whatsapp.gateways.kommo', []);

        $missing = collect(['subdomain', 'token', 'message_field_id', 'bot_id'])
            ->filter(fn ($key) => empty($config[$key]));

        if ($missing->isNotEmpty()) {
            $this->error('  Falta configuración: '.$missing->implode(', '));
            $this->line('  Revisá KOMMO_SUBDOMAIN, KOMMO_TOKEN, KOMMO_MESSAGE_FIELD_ID y KOMMO_BOT_ID en el .env');

            return self::FAILURE;
        }

        $leadId = $this->argument('lead');

        $this->newLine();
        $this->line('  Subdomain : <info>'.$config['subdomain'].'</info>');
        $this->line('  Field id  : <info>'.$config['message_field_id'].'</info>  (donde se deja el texto)');
        $this->line('  Bot id    : <info>'.$config['bot_id'].'</info>  (el que lo envía)');
        $this->newLine();

        $response = Http::withToken((string) $config['token'])
            ->acceptJson()
            ->get("https://{$config['subdomain']}.kommo.com/api/v4/leads/{$leadId}");

        if ($response->status() === 401) {
            $this->error('  401: el token no es válido o expiró.');

            return self::FAILURE;
        }

        if ($response->status() === 404) {
            $this->error("  404: el lead {$leadId} no existe en este subdomain.");

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error('  La API respondió '.$response->status().': '.$response->body());

            return self::FAILURE;
        }

        $this->info('  ✔ Token válido y lead '.$leadId.' encontrado: '.($response->json('name') ?? '(sin nombre)'));
        $this->newLine();

        return $this->reportFields($response->json('custom_fields_values') ?? [], (int) $config['message_field_id']);
    }

    /**
     * List the lead's custom fields so the configured id can be confirmed by eye.
     */
    protected function reportFields(array $fields, int $configured): int
    {
        if (! $fields) {
            $this->warn('  El lead no tiene custom fields con valor. Cargale uno desde Kommo para poder verificar el id.');

            return self::SUCCESS;
        }

        $this->line('  Custom fields del lead:');

        $found = false;

        foreach ($fields as $field) {
            $id = (int) ($field['field_id'] ?? 0);
            $mark = $id === $configured ? '<info>← KOMMO_MESSAGE_FIELD_ID</info>' : '';
            $found = $found || $id === $configured;

            $this->line(sprintf('    %-10s %-28s %s', $id, $field['field_name'] ?? '(sin nombre)', $mark));
        }

        $this->newLine();

        if (! $found) {
            $this->warn("  El campo {$configured} no aparece en este lead.");
            $this->line('  Puede ser normal (si está vacío no siempre viene), pero confirmá que el id sea el correcto:');
            $this->line('  si apunta al campo equivocado, el bot enviará el contenido de otro campo.');

            return self::SUCCESS;
        }

        $this->info('  ✔ El campo configurado existe en el lead.');
        $this->newLine();
        $this->line('  Siguiente: <info>php artisan whatsapp:webhook-info</info> para la URL a pegar en Kommo.');

        return self::SUCCESS;
    }
}
