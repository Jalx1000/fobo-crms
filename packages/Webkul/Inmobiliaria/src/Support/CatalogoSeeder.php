<?php

namespace Webkul\Inmobiliaria\Support;

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeOptionRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Lead\Models\PipelineProxy;
use Webkul\Lead\Repositories\PipelineRepository;
use Webkul\Lead\Repositories\SourceRepository;
use Webkul\Lead\Repositories\StageRepository;
use Webkul\Lead\Repositories\TypeRepository;

/**
 * Applies the catalogue idempotently.
 *
 * Every lookup goes through a natural key — `code` for stages, `name` for
 * everything else — never a hardcoded id, so a re-run finds what a previous run
 * left behind. Anything actually created is recorded in the Manifiesto, which is
 * what makes the rollback safe.
 *
 * All coupling to Krayin's repositories is concentrated here on purpose: if an
 * upgrade changes a signature, the catalogue itself stays untouched.
 */
class CatalogoSeeder
{
    protected bool $dryRun = false;

    protected Manifiesto $manifiesto;

    protected ResultadoCatalogo $resultado;

    public function __construct(
        protected PipelineRepository $pipelines,
        protected StageRepository $stages,
        protected SourceRepository $sources,
        protected TypeRepository $types,
        protected AttributeRepository $attributes,
        protected AttributeOptionRepository $attributeOptions,
    ) {}

    /**
     * @param  array  $catalogo  The Config/catalogo.php payload.
     */
    public function aplicar(array $catalogo, bool $dryRun = false): ResultadoCatalogo
    {
        $this->dryRun = $dryRun;
        $this->manifiesto = Manifiesto::leer();
        $this->resultado = new ResultadoCatalogo;

        // One transaction per entity: a failure part-way leaves whole entities
        // consistent instead of half a catalogue.
        DB::transaction(function () use ($catalogo) {
            $this->aplicarPipelines($catalogo['pipelines']);
            $this->asegurarPorDefecto($catalogo['pipeline_por_defecto']);
        });

        DB::transaction(fn () => $this->aplicarSimples('sources', $catalogo['sources'], $this->sources));
        DB::transaction(fn () => $this->aplicarSimples('types', $catalogo['types'], $this->types));

        DB::transaction(fn () => $this->aplicarAtributos($catalogo['attributes']));

        if (! $this->dryRun) {
            $this->manifiesto->guardar();
        }

        return $this->resultado;
    }

    public function manifiesto(): Manifiesto
    {
        return $this->manifiesto;
    }

    /**
     * Pipelines, with their stages.
     */
    protected function aplicarPipelines(array $definiciones): void
    {
        foreach ($definiciones as $def) {
            $pipeline = PipelineProxy::modelClass()::query()
                ->where('name', $def['name'])
                ->first();

            if ($pipeline) {
                $this->resultado->existente('pipelines', $def['name'], $pipeline->id);

                $this->aplicarEtapas($pipeline->id, $def['name'], $def['stages']);

                continue;
            }

            if ($this->dryRun) {
                $this->resultado->creado('pipelines', $def['name']);

                foreach ($def['stages'] as $etapa) {
                    $this->resultado->creado('stages', $def['name'].' / '.$etapa['code']);
                }

                continue;
            }

            // is_default stays 0 for every pipeline here: create() clears the
            // flag on all others whenever it is set, so creating three in a row
            // with it on would just let the last one win. asegurarPorDefecto()
            // settles it afterwards, and does so on every run.
            //
            // The 'stages' key is mandatory: create() foreaches it with no
            // guard and would fatal on a pipeline defined without stages.
            $pipeline = $this->pipelines->create([
                'name'        => $def['name'],
                'rotten_days' => $def['rotten_days'],
                'is_default'  => 0,
                'stages'      => $this->conSortOrder($def['stages']),
            ]);

            $this->resultado->creado('pipelines', $def['name'], $pipeline->id);
            $this->manifiesto->registrar('pipelines', $pipeline->id);

            foreach ($pipeline->stages as $etapa) {
                $this->resultado->creado('stages', $def['name'].' / '.$etapa->code, $etapa->id);
                $this->manifiesto->registrar('stages', $etapa->id);
            }
        }
    }

    /**
     * Stages of a pipeline that already existed — matched by code, which is
     * unique per pipeline. Never by name: the client can rename a stage from
     * the UI and we would then create a duplicate.
     */
    protected function aplicarEtapas(int $pipelineId, string $nombrePipeline, array $definiciones): void
    {
        foreach ($this->conSortOrder($definiciones) as $etapa) {
            $etiqueta = $nombrePipeline.' / '.$etapa['code'];

            $existente = $this->stages->getModel()->newQuery()
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', $etapa['code'])
                ->first();

            if ($existente) {
                $this->resultado->existente('stages', $etiqueta, $existente->id);

                continue;
            }

            if ($this->dryRun) {
                $this->resultado->creado('stages', $etiqueta);

                continue;
            }

            $creada = $this->stages->create(array_merge($etapa, [
                'lead_pipeline_id' => $pipelineId,
            ]));

            $this->resultado->creado('stages', $etiqueta, $creada->id);
            $this->manifiesto->registrar('stages', $creada->id);
        }
    }

    /**
     * Make the configured pipeline the default one.
     *
     * Done on the model rather than through PipelineRepository::update(), which
     * would be destructive here: it deletes every stage absent from the payload
     * it is handed, and fatals outright without a 'stages' key. Flipping one
     * boolean is not worth that risk.
     */
    protected function asegurarPorDefecto(string $nombre): void
    {
        $pipeline = PipelineProxy::modelClass()::query()->where('name', $nombre)->first();

        if (! $pipeline) {
            if ($this->dryRun) {
                $this->resultado->creado('pipeline_default', $nombre.' (se creará primero)');
            } else {
                $this->resultado->omitido('pipeline_default', $nombre, 'el pipeline no existe');
            }

            return;
        }

        if ((int) $pipeline->is_default === 1) {
            $this->resultado->existente('pipeline_default', $nombre, $pipeline->id);

            return;
        }

        if (! $this->dryRun) {
            // Remember whose flag we are taking, so the rollback can hand it
            // back instead of leaving the instance with no default pipeline.
            $anterior = PipelineProxy::modelClass()::query()
                ->where('is_default', 1)
                ->value('id');

            $this->manifiesto->registrarPrevio('pipeline_default', $anterior);

            PipelineProxy::modelClass()::query()
                ->where('id', '<>', $pipeline->id)
                ->update(['is_default' => 0]);

            $pipeline->update(['is_default' => 1]);
        }

        // Not recorded in the manifest: we did not create this pipeline, we only
        // moved a flag. The rollback restores it separately.
        $this->resultado->creado('pipeline_default', $nombre, $pipeline->id);
    }

    /**
     * Flat name-only catalogues: lead_sources and lead_types.
     *
     * Neither table has a unique index on `name`, so idempotency rests entirely
     * on this lookup. "Web" already exists on this instance and is reported as
     * existing, never recorded in the manifest, and therefore never deleted by
     * the rollback.
     *
     * @param  \Webkul\Core\Eloquent\Repository  $repositorio
     */
    protected function aplicarSimples(string $entidad, array $nombres, $repositorio): void
    {
        foreach ($nombres as $nombre) {
            $existente = $repositorio->getModel()->newQuery()
                ->where('name', $nombre)
                ->first();

            if ($existente) {
                $this->resultado->existente($entidad, $nombre, $existente->id);

                continue;
            }

            if ($this->dryRun) {
                $this->resultado->creado($entidad, $nombre);

                continue;
            }

            $creado = $repositorio->create(['name' => $nombre]);

            $this->resultado->creado($entidad, $nombre, $creado->id);
            $this->manifiesto->registrar($entidad, $creado->id);
        }
    }

    /**
     * Lead attributes and their select options.
     *
     * Attribute and options are upserted as two separate steps rather than
     * handing 'options' to AttributeRepository::create(). That method only
     * inserts options when it creates the attribute (AttributeRepository.php:41);
     * on a re-run the attribute already exists, the repository returns early,
     * and an option newly added to the catalogue would never reach the database.
     */
    protected function aplicarAtributos(array $definiciones): void
    {
        // Appended after the built-in attributes so the qualification block
        // renders as one group at the bottom of the lead form.
        $orden = (int) $this->attributes->getModel()->newQuery()
            ->where('entity_type', 'leads')
            ->max('sort_order');

        foreach ($definiciones as $def) {
            $orden++;

            $existente = $this->attributes->getModel()->newQuery()
                ->where('entity_type', 'leads')
                ->where('code', $def['code'])
                ->first();

            if ($existente) {
                // A code collision with a different type is not something to
                // paper over: the value column would differ and existing data
                // would be unreadable. Report it and leave it alone.
                if ($existente->type !== $def['type']) {
                    $this->resultado->omitido(
                        'attributes',
                        $def['code'],
                        "ya existe con tipo '{$existente->type}', el catálogo pide '{$def['type']}'"
                    );

                    continue;
                }

                $this->resultado->existente('attributes', $def['code'], $existente->id);

                $this->aplicarOpciones($existente->id, $def);

                continue;
            }

            if ($this->dryRun) {
                $this->resultado->creado('attributes', $def['code']);

                foreach ($def['options'] ?? [] as $opcion) {
                    $this->resultado->creado('attribute_options', $def['code'].' / '.$opcion);
                }

                continue;
            }

            // No 'options' key on purpose — see the docblock above.
            $atributo = $this->attributes->create([
                'code'            => $def['code'],
                'name'            => $def['name'],
                'type'            => $def['type'],
                'entity_type'     => 'leads',
                'validation'      => $def['validation'] ?? null,
                'sort_order'      => $orden,
                // Never required: attribute scope is per entity_type, so a
                // required field would block lead creation on every pipeline.
                'is_required'     => 0,
                'is_unique'       => 0,
                'quick_add'       => 0,
                'is_user_defined' => 1,
            ]);

            $this->resultado->creado('attributes', $def['code'], $atributo->id);
            $this->manifiesto->registrar('attributes', $atributo->id);

            $this->aplicarOpciones($atributo->id, $def);
        }
    }

    /**
     * Select options, matched by name within the attribute.
     *
     * attribute_options has no unique index on name, so this lookup is the only
     * thing standing between a re-run and a duplicated dropdown.
     */
    protected function aplicarOpciones(int $atributoId, array $def): void
    {
        $orden = 0;

        foreach ($def['options'] ?? [] as $nombre) {
            $orden++;

            $etiqueta = $def['code'].' / '.$nombre;

            $existente = $this->attributeOptions->getModel()->newQuery()
                ->where('attribute_id', $atributoId)
                ->where('name', $nombre)
                ->first();

            if ($existente) {
                $this->resultado->existente('attribute_options', $etiqueta, $existente->id);

                continue;
            }

            if ($this->dryRun) {
                $this->resultado->creado('attribute_options', $etiqueta);

                continue;
            }

            $opcion = $this->attributeOptions->create([
                'attribute_id' => $atributoId,
                'name'         => $nombre,
                'sort_order'   => $orden,
            ]);

            $this->resultado->creado('attribute_options', $etiqueta, $opcion->id);
            $this->manifiesto->registrar('attribute_options', $opcion->id);
        }
    }

    /**
     * Stage order comes from the catalogue's array order, so the file reads the
     * way the pipeline reads on screen.
     */
    protected function conSortOrder(array $etapas): array
    {
        $orden = 1;

        return array_map(function ($etapa) use (&$orden) {
            return array_merge($etapa, ['sort_order' => $orden++]);
        }, $etapas);
    }
}
