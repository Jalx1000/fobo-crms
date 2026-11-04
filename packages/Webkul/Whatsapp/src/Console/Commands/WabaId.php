<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Descubre el WABA id sin entrar a WhatsApp Manager.
 *
 * Meta no expone la cuenta desde el phone_number_id ni desde el token, pero la
 * manda en cada webhook: `entry[0].id` de una entrega de WhatsApp ES el WABA
 * id. Como los payloads entrantes se guardan enteros, el dato ya está en la
 * base - solo hay que ir a buscarlo.
 */
class WabaId extends Command
{
    protected $signature = 'whatsapp:waba-id';

    protected $description = 'Muestra el WABA id leyéndolo de los webhooks ya recibidos';

    public function handle(): int
    {
        $configured = (string) config('whatsapp.gateways.cloud_api.waba_id');

        if ($configured !== '') {
            $this->line('Configurado hoy: <info>'.$configured.'</info>');
        }

        $found = $this->fromStoredWebhooks();

        if (! $found) {
            $this->warn('No se encontró ningún webhook de Cloud API guardado.');
            $this->line('Sacalo a mano de WhatsApp Manager → Configuración de la cuenta.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Encontrado en los webhooks: <info>'.$found.'</info>');
        $this->newLine();
        $this->line('Ponelo en el .env:');
        $this->line('  <comment>WHATSAPP_WABA_ID='.$found.'</comment>');

        if ($configured !== '' && $configured !== $found) {
            // Un par desalineado no falla al leer el catálogo: falla recién al
            // enviar, con 132001, y ahí ya parece un problema de la plantilla.
            $this->newLine();
            $this->error('El valor configurado NO coincide con el de los webhooks. Revisá cuál corresponde al número en uso.');
        }

        return self::SUCCESS;
    }

    /**
     * El `entry[0].id` del payload entrante más reciente.
     */
    protected function fromStoredWebhooks(): ?string
    {
        $rows = DB::table('whatsapp_messages')
            ->join('whatsapp_conversations', 'whatsapp_messages.conversation_id', '=', 'whatsapp_conversations.id')
            ->where('whatsapp_conversations.gateway', 'cloud_api')
            ->whereNotNull('whatsapp_messages.payload')
            ->orderByDesc('whatsapp_messages.id')
            ->limit(50)
            ->pluck('whatsapp_messages.payload');

        foreach ($rows as $payload) {
            $decoded = is_array($payload) ? $payload : json_decode((string) $payload, true);

            $id = $decoded['entry'][0]['id'] ?? null;

            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }
}
