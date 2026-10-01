<?php

namespace Webkul\Lead\Services;

use Webkul\Attribute\Models\AttributeProxy;
use Webkul\Attribute\Models\AttributeValueProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Models\StageProxy;

/**
 * Mantiene sincronizada la "ciudad" en sus tres representaciones:
 *
 *   1. leads.lead_pipeline_id            (la columna real; un pipeline = una ciudad)
 *   2. atributo custom `Ciudad`          (entity_type = leads,   lookup a lead_pipelines)
 *   3. atributo custom `cliente_ciudad`  (entity_type = persons, lookup a lead_pipelines)
 *
 * Los tres guardan el mismo dominio de valores (ids de `lead_pipelines`), así que
 * sincronizar es propagar un entero. Reglas acordadas con el negocio:
 *
 *   - Si en un mismo guardado cambian el pipeline y el atributo `Ciudad` a valores
 *     distintos, gana `lead_pipeline_id` (es lo que ven kanban, tablero y reportes).
 *   - Si sólo cambia el atributo `Ciudad`, éste arrastra al lead a ese pipeline y la
 *     etapa se remapea por `code` (todos los pipelines comparten los mismos codes).
 *   - El lead siempre propaga su ciudad a la persona (`cliente_ciudad`).
 *   - Al cambiar `cliente_ciudad` de una persona, sólo se mueven sus leads ABIERTOS;
 *     los cerrados se dejan intactos para no reescribir el histórico del tablero.
 */
class CitySyncService
{
    /**
     * Code del atributo custom de ciudad del lead. Ojo: va con mayúscula inicial,
     * así está creado en `attributes`.
     */
    public const LEAD_CITY_ATTRIBUTE_CODE = 'Ciudad';

    /**
     * Code del atributo custom de ciudad de la persona.
     */
    public const PERSON_CITY_ATTRIBUTE_CODE = 'cliente_ciudad';

    /**
     * Etapas consideradas "cerradas": un lead en una de ellas ya no se mueve de
     * ciudad cuando la persona cambia de ciudad. Solo siguen al contacto los leads
     * realmente activos ("No atendido" y "Cliente confirmado"); el resto se queda
     * en la ciudad donde se resolvió.
     */
    public const CLOSED_STAGE_CODES = [
        'pedidos-entregados',
        'pedidos-cancelados',
        'cliente-sin-inters',
        'otros-servicios',
    ];

    /**
     * Candado anti-recursión: lead -> persona -> leads podría ciclar.
     */
    private static bool $running = false;

    /**
     * Cache en memoria de los ids de atributo, resueltos por code.
     *
     * @var array<string, int|null>
     */
    private array $attributeIds = [];

    /**
     * Propaga la ciudad del lead al atributo `Ciudad` y a la persona.
     *
     * @param  \Webkul\Lead\Contracts\Lead  $lead
     * @param  int|null  $previousPipelineId  Pipeline del lead ANTES del guardado.
     */
    public function syncFromLead($lead, ?int $previousPipelineId = null): void
    {
        if (self::$running) {
            return;
        }

        self::$running = true;

        try {
            $city = $this->resolveLeadCity($lead, $previousPipelineId);

            if (! $city) {
                return;
            }

            /**
             * El atributo `Ciudad` ganó: el lead se muda de pipeline y la etapa se
             * remapea a su equivalente por code en el pipeline destino.
             */
            if ($city !== (int) $lead->lead_pipeline_id) {
                $lead->lead_pipeline_id = $city;
                $lead->lead_pipeline_stage_id = $this->mapStageToPipeline($lead->lead_pipeline_stage_id, $city);
                $lead->save();
            }

            $this->writeAttributeValue('leads', $lead->id, self::LEAD_CITY_ATTRIBUTE_CODE, $city);

            if ($lead->person_id) {
                $this->writeAttributeValue('persons', $lead->person_id, self::PERSON_CITY_ATTRIBUTE_CODE, $city);
            }
        } finally {
            self::$running = false;
        }
    }

    /**
     * Propaga la ciudad de la persona a sus leads abiertos.
     *
     * @param  \Webkul\Contact\Contracts\Person  $person
     */
    public function syncFromPerson($person): void
    {
        if (self::$running) {
            return;
        }

        $city = $this->readAttributeValue('persons', $person->id, self::PERSON_CITY_ATTRIBUTE_CODE);

        if (! $city) {
            return;
        }

        self::$running = true;

        try {
            $closedStageIds = $this->closedStageIds();

            $leads = LeadProxy::modelClass()::query()
                ->where('person_id', $person->id)
                ->where('lead_pipeline_id', '!=', $city)
                ->whereNotIn('lead_pipeline_stage_id', $closedStageIds)
                ->get();

            foreach ($leads as $lead) {
                $lead->lead_pipeline_id = $city;
                $lead->lead_pipeline_stage_id = $this->mapStageToPipeline($lead->lead_pipeline_stage_id, $city);
                $lead->save();

                $this->writeAttributeValue('leads', $lead->id, self::LEAD_CITY_ATTRIBUTE_CODE, $city);
            }
        } finally {
            self::$running = false;
        }
    }

    /**
     * Decide qué ciudad debe quedar tras guardar un lead.
     *
     * El pipeline gana cuando cambió en este guardado; si no cambió, un atributo
     * `Ciudad` distinto es una edición explícita del usuario y arrastra al lead.
     */
    private function resolveLeadCity($lead, ?int $previousPipelineId): ?int
    {
        $pipelineId = (int) $lead->lead_pipeline_id ?: null;

        if ($previousPipelineId !== null && $pipelineId !== $previousPipelineId) {
            return $pipelineId;
        }

        $attributeCity = $this->readAttributeValue('leads', $lead->id, self::LEAD_CITY_ATTRIBUTE_CODE);

        return $attributeCity ?: $pipelineId;
    }

    /**
     * Traduce una etapa al pipeline destino usando su `code`, que es común a todos
     * los pipelines. Si no hay equivalente, cae en la primera etapa del destino.
     */
    private function mapStageToPipeline(?int $stageId, int $pipelineId): ?int
    {
        $stageModel = StageProxy::modelClass();

        $code = $stageId
            ? $stageModel::query()->where('id', $stageId)->value('code')
            : null;

        if ($code) {
            $mapped = $stageModel::query()
                ->where('lead_pipeline_id', $pipelineId)
                ->where('code', $code)
                ->value('id');

            if ($mapped) {
                return (int) $mapped;
            }
        }

        $first = $stageModel::query()
            ->where('lead_pipeline_id', $pipelineId)
            ->orderBy('sort_order')
            ->value('id');

        return $first ? (int) $first : $stageId;
    }

    /**
     * Ids de todas las etapas cerradas, en todos los pipelines.
     *
     * @return array<int, int>
     */
    private function closedStageIds(): array
    {
        return StageProxy::modelClass()::query()
            ->whereIn('code', self::CLOSED_STAGE_CODES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Lee el valor de un atributo de ciudad (siempre un id de pipeline).
     */
    private function readAttributeValue(string $entityType, int $entityId, string $code): ?int
    {
        $attributeId = $this->attributeId($entityType, $code);

        if (! $attributeId) {
            return null;
        }

        $value = AttributeValueProxy::modelClass()::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('attribute_id', $attributeId)
            ->value('integer_value');

        return $value ? (int) $value : null;
    }

    /**
     * Escribe el valor de un atributo de ciudad, sin tocar la fila si ya coincide.
     */
    private function writeAttributeValue(string $entityType, int $entityId, string $code, int $city): void
    {
        $attributeId = $this->attributeId($entityType, $code);

        if (! $attributeId) {
            return;
        }

        $model = AttributeValueProxy::modelClass();

        $row = $model::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('attribute_id', $attributeId)
            ->first();

        if ($row) {
            if ((int) $row->integer_value !== $city) {
                $row->integer_value = $city;
                $row->save();
            }

            return;
        }

        $model::query()->create([
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'attribute_id'  => $attributeId,
            'integer_value' => $city,
        ]);
    }

    /**
     * Resuelve el id del atributo por code + entity_type, cacheado por request.
     */
    private function attributeId(string $entityType, string $code): ?int
    {
        $key = $entityType.':'.$code;

        if (! array_key_exists($key, $this->attributeIds)) {
            $id = AttributeProxy::modelClass()::query()
                ->where('entity_type', $entityType)
                ->where('code', $code)
                ->value('id');

            $this->attributeIds[$key] = $id ? (int) $id : null;
        }

        return $this->attributeIds[$key];
    }
}
