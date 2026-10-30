<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Par de claves RSA del endpoint de WhatsApp Flows.
 *
 * Meta cifra cada pedido del Flow con NUESTRA clave pública; la privada queda
 * en el servidor (fuera del repo) y es la que usa FlowCrypto para descifrar.
 *
 *   php artisan whatsapp:flows:keys --upload    # genera (si falta) y sube a Meta
 *   php artisan whatsapp:flows:keys --status    # solo consulta el estado en Meta
 *
 * https://developers.facebook.com/docs/whatsapp/cloud-api/reference/whatsapp-business-encryption
 */
class FlowsKeys extends Command
{
    protected $signature = 'whatsapp:flows:keys
        {--upload : Sube la clave pública a Meta (POST /{phone_number_id}/whatsapp_business_encryption)}
        {--status : Solo consulta el estado de la clave en Meta}
        {--force : Regenera el par aunque ya exista (invalida la clave subida hasta re-subirla)}';

    protected $description = 'Genera y sube a Meta la clave del endpoint de WhatsApp Flows';

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->status();
        }

        $path = (string) config('whatsapp.flows.private_key_path');
        $passphrase = config('whatsapp.flows.private_key_passphrase');

        if (is_readable($path) && ! $this->option('force')) {
            $this->info("Usando la clave privada existente: {$path}");
            $key = openssl_pkey_get_private((string) file_get_contents($path), $passphrase ?? '');

            if ($key === false) {
                $this->error('La clave privada existente no se puede abrir (¿passphrase incorrecta?).');

                return self::FAILURE;
            }
        } else {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }

            openssl_pkey_export($key, $pem, $passphrase ?: null);
            file_put_contents($path, $pem);
            chmod($path, 0600);

            $this->info("Clave privada generada en {$path} (permisos 0600). No la subas al repo.");
        }

        $public = openssl_pkey_get_details($key)['key'];
        $this->line($public);

        if (! $this->option('upload')) {
            $this->warn('No se subió a Meta. Correr con --upload para que el endpoint funcione.');

            return self::SUCCESS;
        }

        $response = Http::withToken((string) config('whatsapp.cloud.token'))
            ->asForm()
            ->post($this->url(), ['business_public_key' => $public]);

        if (! $response->successful()) {
            $this->error('Meta rechazó la clave: '.$response->body());

            return self::FAILURE;
        }

        $this->info('Clave pública subida a Meta.');

        return $this->status();
    }

    protected function status(): int
    {
        $response = Http::withToken((string) config('whatsapp.cloud.token'))->get($this->url());

        if (! $response->successful()) {
            $this->error('No se pudo consultar el estado: '.$response->body());

            return self::FAILURE;
        }

        $status = $response->json('data.0.business_public_key_signature_status') ?? json_encode($response->json());
        $this->info("Estado de la clave en Meta: {$status}");

        return self::SUCCESS;
    }

    protected function url(): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s/whatsapp_business_encryption',
            config('whatsapp.cloud.api_version', 'v21.0'),
            config('whatsapp.cloud.phone_number_id'),
        );
    }
}
