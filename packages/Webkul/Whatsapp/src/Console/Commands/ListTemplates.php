<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Services\TemplateCatalog;

/**
 * Show the approved templates the composer can offer.
 *
 * Doubles as the diagnostic for "why does the template list come up empty?",
 * which is otherwise invisible: an account with no approved templates and a
 * broken token look exactly the same from the inbox.
 */
class ListTemplates extends Command
{
    protected $signature = 'whatsapp:templates {--fresh : Ignore the cache and read from Meta}';

    protected $description = 'List the WhatsApp templates approved for this account.';

    public function handle(TemplateCatalog $catalog, GatewayManager $gateways): int
    {
        if (! $gateways->active()->capabilities()->canSend('template')) {
            $this->warn('El canal activo ('.$gateways->active()->key().') no envía plantillas.');

            return self::SUCCESS;
        }

        try {
            $templates = $catalog->all((bool) $this->option('fresh'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $templates) {
            $this->warn('No hay plantillas APROBADAS en esta cuenta.');
            $this->line('Se dan de alta en WhatsApp Manager; Meta tarda entre 24 y 48 h en aprobarlas.');

            return self::SUCCESS;
        }

        $this->table(
            ['Nombre', 'Idioma', 'Categoría', 'Vars', 'Cuerpo'],
            array_map(fn ($t) => [
                $t['name'],
                $t['language'],
                $t['category'] ?? '—',
                $t['variables'],
                mb_strimwidth(str_replace("\n", ' ', $t['body']), 0, 60, '…'),
            ], $templates),
        );

        $this->info(count($templates).' plantilla(s) aprobada(s).');

        return self::SUCCESS;
    }
}
