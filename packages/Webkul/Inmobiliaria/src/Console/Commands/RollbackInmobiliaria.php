<?php

namespace Webkul\Inmobiliaria\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Inmobiliaria\Support\Manifiesto;
use Webkul\Lead\Models\PipelineProxy;

/**
 * Undoes crm:setup-inmobiliaria.
 *
 * Deletes only what the manifest says the setup created — never what the
 * catalogue merely lists. That distinction matters: "Web" appears in the
 * catalogue and already existed on this instance as lead_sources.id = 2, so a
 * catalogue-driven rollback would destroy a row that was never ours.
 *
 * Refuses to delete anything that has data hanging off it, and refuses as a
 * whole rather than partially: a rollback that runs half-way is worse than one
 * that does not run.
 */
class RollbackInmobiliaria extends Command
{
    /**
     * @var string
     */
    protected $signature = 'crm:rollback-inmobiliaria
                            {--dry-run : Muestra lo que borraría sin borrar nada}';

    /**
     * @var string
     */
    protected $description = 'Revierte crm:setup-inmobiliaria, borrando únicamente lo que ese comando creó.';

    /**
     * Tables holding what the manifest tracks, in deletion order, plus the
     * lead column that would block each one.
     */
    protected const TABLAS = [
        'attribute_options' => 'attribute_options',
        'attributes'        => 'attributes',
        'stages'            => 'lead_pipeline_stages',
        'pipelines'         => 'lead_pipelines',
        'sources'           => 'lead_sources',
        'types'             => 'lead_types',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line('  <options=bold>Rollback del catálogo inmobiliario</>');

        if ($dryRun) {
            $this->warn('  Modo simulación: no se borra nada.');
        }

        $manifiesto = Manifiesto::leer();

        if (! $manifiesto->existe()) {
            $this->newLine();
            $this->error('  No hay manifiesto: este comando no sabe qué creó el setup.');
            $this->line('  Sin esa información no se borra nada, para no tocar datos preexistentes.');
            $this->line('  Si necesitás revertir igual, restaurá el dump de <options=bold>backups/</>.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Manifiesto aplicado el <options=bold>'.$manifiesto->aplicadoEn().'</>, '.$manifiesto->total().' registro(s).');

        if ($manifiesto->total() === 0) {
            $this->newLine();
            $this->info('  El setup no creó nada: no hay qué revertir.');
            $manifiesto->olvidar();

            return self::SUCCESS;
        }

        if ($bloqueos = $this->guardas($manifiesto)) {
            $this->newLine();
            $this->error('  Rollback cancelado: hay datos asociados.');

            foreach ($bloqueos as $bloqueo) {
                $this->line('    <fg=red>✗</> '.$bloqueo);
            }

            $this->newLine();
            $this->line('  No se borró nada. Mové o eliminá esos leads antes de reintentar.');

            return self::FAILURE;
        }

        $this->line('  <fg=green>✓</> Sin leads ni valores asociados: se puede revertir.');
        $this->newLine();

        return $this->borrar($manifiesto, $dryRun);
    }

    /**
     * Everything that must be clear before a single row is deleted.
     *
     * Checked up front and in full, so the operator sees every blocker at once
     * instead of discovering them one failed run at a time.
     *
     * @return string[]
     */
    protected function guardas(Manifiesto $manifiesto): array
    {
        $bloqueos = [];

        $comprobaciones = [
            ['pipelines', 'lead_pipeline_id',       'pipeline(s)'],
            ['stages',    'lead_pipeline_stage_id', 'etapa(s)'],
            ['sources',   'lead_source_id',         'source(s)'],
            ['types',     'lead_type_id',           'type(s)'],
        ];

        foreach ($comprobaciones as [$entidad, $columna, $rotulo]) {
            $ids = $manifiesto->ids($entidad);

            if (! $ids) {
                continue;
            }

            $leads = DB::table('leads')->whereIn($columna, $ids)->count();

            if ($leads > 0) {
                $afectados = DB::table('leads')->whereIn($columna, $ids)->distinct()->count($columna);
                $bloqueos[] = "{$leads} lead(s) usan {$afectados} {$rotulo} del manifiesto ({$columna})";
            }
        }

        if ($ids = $manifiesto->ids('attributes')) {
            $valores = DB::table('attribute_values')->whereIn('attribute_id', $ids)->count();

            if ($valores > 0) {
                $bloqueos[] = "{$valores} valor(es) cargados en atributos del manifiesto (attribute_values)";
            }
        }

        return $bloqueos;
    }

    protected function borrar(Manifiesto $manifiesto, bool $dryRun): int
    {
        try {
            DB::transaction(function () use ($manifiesto, $dryRun) {
                // Child-first: attribute_options before attributes, stages
                // before pipelines. The FKs cascade anyway, but deleting in this
                // order keeps the reported counts honest.
                foreach (self::TABLAS as $entidad => $tabla) {
                    $ids = $manifiesto->ids($entidad);

                    if (! $ids) {
                        continue;
                    }

                    $presentes = DB::table($tabla)->whereIn('id', $ids)->count();

                    if (! $dryRun) {
                        DB::table($tabla)->whereIn('id', $ids)->delete();
                    }

                    $ausentes = count($ids) - $presentes;

                    $this->line(sprintf(
                        '    <fg=red>-</> %-18s %d de %d%s',
                        $entidad.':',
                        $presentes,
                        count($ids),
                        $ausentes > 0 ? " <fg=gray>({$ausentes} ya no estaban)</>" : ''
                    ));
                }

                $this->restaurarPorDefecto($manifiesto, $dryRun);

                if (! $dryRun) {
                    $manifiesto->olvidar();
                }
            });
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('  Falló el rollback: '.$e->getMessage());
            $this->line('  La transacción se revirtió: no se borró nada.');

            return self::FAILURE;
        }

        $this->newLine();

        if ($dryRun) {
            $this->line('  Volvé a correr sin <options=bold>--dry-run</> para revertir de verdad.');

            return self::SUCCESS;
        }

        $this->info('  Rollback completo. El manifiesto fue eliminado.');

        return self::SUCCESS;
    }

    /**
     * Hand the is_default flag back.
     *
     * Deleting our pipelines would otherwise leave the instance with no default
     * at all. The manifest records whose flag we took; when it does not — a
     * manifest written before that was tracked — the oldest surviving pipeline
     * is promoted, which on this instance is the original default anyway.
     */
    protected function restaurarPorDefecto(Manifiesto $manifiesto, bool $dryRun): void
    {
        $modelo = PipelineProxy::modelClass();

        $anterior = $manifiesto->previo('pipeline_default');

        $destino = $anterior
            ? $modelo::query()->where('id', $anterior)->first()
            : null;

        $origen = $destino ? 'del manifiesto' : 'el más antiguo que queda';

        if (! $destino) {
            $destino = $modelo::query()
                ->whereNotIn('id', $manifiesto->ids('pipelines'))
                ->orderBy('id')
                ->first();
        }

        if (! $destino) {
            $this->line('    <fg=yellow>!</> No queda ningún pipeline al que devolverle is_default.');

            return;
        }

        if (! $dryRun) {
            $modelo::query()->where('id', '<>', $destino->id)->update(['is_default' => 0]);
            $destino->update(['is_default' => 1]);
        }

        $this->line(sprintf(
            '    <fg=green>~</> %-18s "%s" vuelve a ser el pipeline por defecto <fg=gray>(%s)</>',
            'is_default:',
            $destino->name,
            $origen
        ));
    }
}
