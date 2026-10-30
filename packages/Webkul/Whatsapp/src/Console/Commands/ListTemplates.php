<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Whatsapp\Services\TemplateCatalog;

/**
 * Shows the approved templates the inbox can offer, and refreshes the cache.
 *
 * Exists so the catalogue can be checked without opening the UI: when a
 * template is missing, this tells apart "not approved yet" from "wrong account
 * configured", which is otherwise invisible from the front.
 */
class ListTemplates extends Command
{
    protected $signature = 'whatsapp:templates
        {--fresh : Ignore the cache and ask Meta again}';

    protected $description = 'Lista las plantillas de WhatsApp aprobadas';

    public function handle(TemplateCatalog $catalog): int
    {
        try {
            $templates = $catalog->all($this->option('fresh'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $templates) {
            $this->warn('No hay plantillas aprobadas en esta cuenta.');

            return self::SUCCESS;
        }

        $this->table(
            ['Nombre', 'Idioma', 'Categoría', 'Vars', 'Botones'],
            array_map(fn ($t) => [
                $t['name'],
                $t['language'],
                $t['category'] ?? '-',
                $t['variables'],
                $t['buttons'] ? implode(' / ', $t['buttons']) : '-',
            ], $templates),
        );

        $this->newLine();

        foreach ($templates as $template) {
            $this->line("<info>{$template['name']}</info> ({$template['language']})");

            if ($template['header']) {
                $this->line('  <comment>Encabezado:</comment> '.$template['header']);
            }

            $this->line('  '.str_replace("\n", "\n  ", $template['body']));

            if ($template['examples']) {
                $this->line('  <comment>Ejemplos:</comment> '.implode(' | ', $template['examples']));
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
