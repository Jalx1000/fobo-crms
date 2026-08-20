<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Webkul\Whatsapp\Gateways\Dto\WebhookSecurity;
use Webkul\Whatsapp\Gateways\GatewayManager;

class WebhookInfo extends Command
{
    /**
     * @var string
     */
    protected $signature = 'whatsapp:webhook-info
        {channel? : Channel key (cloud_api, kommo, messenger). Defaults to the active WhatsApp provider}
        {--generate-secret : Print a fresh high-entropy secret for URL-authenticated providers}';

    /**
     * @var string
     */
    protected $description = 'Show the webhook URL to paste into a provider panel, and its security posture.';

    public function handle(GatewayManager $gateways): int
    {
        if ($this->option('generate-secret')) {
            $this->line(Str::random(48));

            return self::SUCCESS;
        }

        $channel = (string) ($this->argument('channel') ?: config('whatsapp.gateway'));

        if (! $gateways->has($channel)) {
            $this->error("  El canal [{$channel}] no está registrado. Disponibles: ".implode(', ', $gateways->keys()));

            return self::FAILURE;
        }

        $gateway = $gateways->driver($channel);
        $security = $gateway->webhookSecurity();

        // Channels pinned to their own endpoint publish it under /webhooks/<key>;
        // the WhatsApp providers share the one the Meta app already points at.
        $base = rtrim((string) config('app.url'), '/')
            .($channel === 'messenger' ? '/api/v1/webhooks/messenger' : '/api/v1/whatsapp/webhook');

        $this->newLine();
        $this->line('  Canal:          <info>'.$gateway->key().'</info> ('.$gateways->label($channel).')');
        $this->line('  Seguridad:      '.$this->describe($security));
        $this->newLine();

        match ($security) {
            WebhookSecurity::SIGNATURE  => $this->signatureHelp($base, $channel),
            WebhookSecurity::URL_SECRET => $this->urlSecretHelp($base, $channel),
            default                     => $this->line('  URL: '.$base),
        };

        $this->newLine();
        $this->line('  Capacidades de envío: <info>'.implode(', ', $gateway->capabilities()->send).'</info>');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function describe(WebhookSecurity $security): string
    {
        return match ($security) {
            WebhookSecurity::SIGNATURE    => '<info>firma HMAC del cuerpo</info> (fuerte)',
            WebhookSecurity::HEADER_TOKEN => '<comment>token en header</comment> (media)',
            WebhookSecurity::URL_SECRET   => '<comment>secreto en la URL</comment> (el proveedor no firma)',
            WebhookSecurity::NONE         => '<error>NINGUNA — cualquiera puede inyectar mensajes</error>',
        };
    }

    protected function signatureHelp(string $base, string $channel): void
    {
        $this->line('  URL a pegar en el panel del proveedor:');
        $this->line('    <info>'.$base.'</info>');
        $this->newLine();
        $this->line('  Cada evento se valida con HMAC contra '
            .($channel === 'messenger' ? 'MESSENGER_APP_SECRET' : 'WHATSAPP_APP_SECRET').'.');
    }

    protected function urlSecretHelp(string $base, string $channel): void
    {
        $secret = (string) config('whatsapp.gateways.'.$channel.'.webhook_secret');

        if ($secret === '') {
            $this->error('  Falta el secreto del webhook: este proveedor no firma nada, así que');
            $this->error('  sin él cualquiera que descubra la URL puede inyectar mensajes falsos.');
            $this->newLine();
            $this->line('  Generá uno con:  <info>php artisan whatsapp:webhook-info --generate-secret</info>');
            $this->line('  y guardalo en el .env del proveedor (p. ej. KOMMO_WEBHOOK_SECRET).');

            return;
        }

        $this->line('  URL a pegar en el panel del proveedor:');
        $this->line('    <info>'.$base.'/'.$secret.'</info>');
        $this->newLine();
        $this->line('  El secreto de la URL <comment>es</comment> la credencial: tratala como una contraseña.');
    }
}
