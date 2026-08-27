<?php

namespace Webkul\Inmobiliaria\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Inmobiliaria\Support\CatalogoSeeder;
use Webkul\Inmobiliaria\Support\ResultadoCatalogo;

/**
 * Applies the real-estate catalogue to this instance.
 *
 * Safe to re-run: everything is matched by natural key, so a second run reports
 * zero changes. Always run with --dry-run first, and take a mysqldump before the
 * real one — this work has no automated test coverage (decision D4).
 */
class SetupInmobiliaria extends Command
{
    /**
     * @var string
     */
    protected $signature = 'crm:setup-inmobiliaria
                            {--dry-run : Muestra lo que haría sin escribir nada}';

    /**
     * @var string
     */
    protected $description = 'Crea los pipelines, etapas, atributos, sources y types de la operación inmobiliaria (idempotente).';

    public function handle(CatalogoSeeder $seeder, AttributeRepository $attributes): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line('  <options=bold>Catálogo inmobiliario — CENTURY 21</>');

        if ($dryRun) {
            $this->warn('  Modo simulación: no se escribe nada en la base de datos.');
        }

        $this->newLine();

        try {
            $resultado = $seeder->aplicar(config('inmobiliaria'), $dryRun);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('  Falló la aplicación del catálogo: '.$e->getMessage());
            $this->line('  <fg=gray>'.$e->getFile().':'.$e->getLine().'</>');
            $this->line('  No se aplicó nada: cada entidad corre en su propia transacción.');

            return self::FAILURE;
        }

        $this->reportar($resultado);

        if (! $dryRun) {
            $this->mapaDeOpciones($attributes);
        }

        $this->newLine();

        if (! $resultado->hayCambios()) {
            $this->info('  Sin cambios: el catálogo ya estaba aplicado.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('  Volvé a correr sin <options=bold>--dry-run</> para aplicarlo.');
            $this->line('  <fg=yellow>Antes: hacé el backup con mysqldump.</>');

            return self::SUCCESS;
        }

        $this->info('  Catálogo aplicado.');
        $this->line('  Verificá con: <options=bold>php artisan crm:setup-inmobiliaria --dry-run</> (debe reportar 0 cambios).');

        return self::SUCCESS;
    }

    /**
     * Detail is opt-in through -v: 25 stages plus 49 options would bury the
     * summary. Anything skipped is always shown, because that is the line an
     * operator actually needs to read.
     */
    protected function reportar(ResultadoCatalogo $resultado): void
    {
        $simbolos = [
            ResultadoCatalogo::CREADO    => '<fg=green>+</>',
            ResultadoCatalogo::EXISTENTE => '<fg=gray>=</>',
            ResultadoCatalogo::OMITIDO   => '<fg=yellow>!</>',
            ResultadoCatalogo::ELIMINADO => '<fg=red>-</>',
        ];

        foreach ($resultado->entidades() as $entidad) {
            $items = $resultado->items($entidad);

            $visibles = $this->output->isVerbose()
                ? $items
                : array_filter($items, fn ($i) => $i['estado'] === ResultadoCatalogo::OMITIDO);

            if (! $visibles) {
                continue;
            }

            $this->line('  <fg=cyan>'.$entidad.'</>');

            foreach ($visibles as $item) {
                $this->line(sprintf(
                    '    %s %s%s',
                    $simbolos[$item['estado']] ?? '?',
                    $item['etiqueta'],
                    $item['motivo'] ? ' <fg=yellow>('.$item['motivo'].')</>' : ''
                ));
            }
        }

        $this->newLine();
        $this->line('  <options=bold>Resumen</>');

        foreach ($resultado->resumen() as $linea) {
            $this->line('    '.$linea);
        }

        if (! $this->output->isVerbose()) {
            $this->line('    <fg=gray>(usá -v para el detalle ítem por ítem)</>');
        }
    }

    /**
     * Prints code -> {option: id} for every select.
     *
     * A select attribute stores attribute_options.id in integer_value, not the
     * label, so n8n cannot write these fields without the mapping. Emitting it
     * here means nobody has to hardcode an id (success criterion SC10).
     */
    protected function mapaDeOpciones(AttributeRepository $attributes): void
    {
        $selects = $attributes->getModel()->newQuery()
            ->where('entity_type', 'leads')
            ->where('is_user_defined', 1)
            ->where('type', 'select')
            ->with('options')
            ->orderBy('sort_order')
            ->get();

        if ($selects->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('  <options=bold>Mapa de opciones para n8n</> <fg=gray>(select guarda el id en integer_value)</>');

        foreach ($selects as $atributo) {
            $pares = $atributo->options
                ->map(fn ($o) => $o->name.'='.$o->id)
                ->implode(', ');

            $this->line('    <fg=cyan>'.$atributo->code.'</> '.$pares);
        }
    }
}
