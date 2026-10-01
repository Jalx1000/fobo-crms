<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Services\CitySyncService;

/**
 * La suite de este proyecto corre contra la base real (no hay RefreshDatabase),
 * así que estas pruebas se envuelven en una transacción que se revierte al final.
 */
uses(DatabaseTransactions::class);

/**
 * Id del atributo de ciudad de leads (`Ciudad`) o de personas (`cliente_ciudad`).
 */
function cityAttributeId(string $entityType, string $code): int
{
    return (int) DB::table('attributes')
        ->where('entity_type', $entityType)
        ->where('code', $code)
        ->value('id');
}

/**
 * Valor de ciudad guardado para una entidad, o null si no hay ninguno.
 */
function cityValueOf(string $entityType, int $entityId, string $code): ?int
{
    $value = DB::table('attribute_values')
        ->where('entity_type', $entityType)
        ->where('entity_id', $entityId)
        ->where('attribute_id', cityAttributeId($entityType, $code))
        ->value('integer_value');

    return $value !== null ? (int) $value : null;
}

/**
 * Id de la etapa con ese code dentro del pipeline indicado.
 */
function stageIdIn(int $pipelineId, string $code): int
{
    return (int) DB::table('lead_pipeline_stages')
        ->where('lead_pipeline_id', $pipelineId)
        ->where('code', $code)
        ->value('id');
}

/**
 * Crea una persona suelta (sin ciudad) para colgarle leads.
 */
function makePerson(): int
{
    return (int) DB::table('persons')->insertGetId([
        'name'            => 'Test Sync Ciudad '.uniqid(),
        'emails'          => json_encode([['value' => 'sync-'.uniqid().'@test.local', 'label' => 'work']]),
        'contact_numbers' => json_encode([['value' => '70000000', 'label' => 'work']]),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

beforeEach(function () {
    /**
     * Tarija (1) y Santa Cruz (3) son pipelines reales; el test solo lee sus ids
     * de etapa por code, nunca los hardcodea.
     */
    $this->cityA = 1;
    $this->cityB = 3;
});

it('propaga la ciudad del lead al atributo Ciudad y a la persona al crear', function () {
    $personId = makePerson();

    $lead = app(LeadRepository::class)->create([
        'title'                  => 'Lead sync create',
        'entity_type'            => 'leads',
        'person_id'              => $personId,
        'lead_pipeline_id'       => $this->cityA,
        'lead_pipeline_stage_id' => stageIdIn($this->cityA, 'no-atendido'),
    ]);

    expect(cityValueOf('leads', $lead->id, CitySyncService::LEAD_CITY_ATTRIBUTE_CODE))->toBe($this->cityA)
        ->and(cityValueOf('persons', $personId, CitySyncService::PERSON_CITY_ATTRIBUTE_CODE))->toBe($this->cityA);
});

it('actualiza ambos atributos cuando el lead cambia de pipeline', function () {
    $personId = makePerson();
    $repository = app(LeadRepository::class);

    $lead = $repository->create([
        'title'                  => 'Lead sync move',
        'entity_type'            => 'leads',
        'person_id'              => $personId,
        'lead_pipeline_id'       => $this->cityA,
        'lead_pipeline_stage_id' => stageIdIn($this->cityA, 'no-atendido'),
    ]);

    $repository->update([
        'entity_type'            => 'leads',
        'lead_pipeline_id'       => $this->cityB,
        'lead_pipeline_stage_id' => stageIdIn($this->cityB, 'no-atendido'),
    ], $lead->id);

    expect(cityValueOf('leads', $lead->id, CitySyncService::LEAD_CITY_ATTRIBUTE_CODE))->toBe($this->cityB)
        ->and(cityValueOf('persons', $personId, CitySyncService::PERSON_CITY_ATTRIBUTE_CODE))->toBe($this->cityB);
});

it('mueve el lead de pipeline cuando solo cambia el atributo Ciudad, remapeando la etapa', function () {
    $personId = makePerson();
    $repository = app(LeadRepository::class);

    $lead = $repository->create([
        'title'                  => 'Lead sync attribute wins',
        'entity_type'            => 'leads',
        'person_id'              => $personId,
        'lead_pipeline_id'       => $this->cityA,
        'lead_pipeline_stage_id' => stageIdIn($this->cityA, 'cliente-confirmado'),
    ]);

    // Solo se edita el atributo: el pipeline no viaja en el request.
    $repository->update([
        'entity_type'                              => 'leads',
        CitySyncService::LEAD_CITY_ATTRIBUTE_CODE  => $this->cityB,
    ], $lead->id);

    $lead->refresh();

    expect((int) $lead->lead_pipeline_id)->toBe($this->cityB)
        ->and((int) $lead->lead_pipeline_stage_id)->toBe(stageIdIn($this->cityB, 'cliente-confirmado'))
        ->and(cityValueOf('persons', $personId, CitySyncService::PERSON_CITY_ATTRIBUTE_CODE))->toBe($this->cityB);
});

it('deja ganar al pipeline cuando pipeline y atributo Ciudad cambian a la vez', function () {
    $personId = makePerson();
    $repository = app(LeadRepository::class);

    $lead = $repository->create([
        'title'                  => 'Lead sync conflict',
        'entity_type'            => 'leads',
        'person_id'              => $personId,
        'lead_pipeline_id'       => $this->cityA,
        'lead_pipeline_stage_id' => stageIdIn($this->cityA, 'no-atendido'),
    ]);

    $repository->update([
        'entity_type'                             => 'leads',
        'lead_pipeline_id'                        => $this->cityB,
        'lead_pipeline_stage_id'                  => stageIdIn($this->cityB, 'no-atendido'),
        CitySyncService::LEAD_CITY_ATTRIBUTE_CODE => $this->cityA,
    ], $lead->id);

    $lead->refresh();

    expect((int) $lead->lead_pipeline_id)->toBe($this->cityB)
        ->and(cityValueOf('leads', $lead->id, CitySyncService::LEAD_CITY_ATTRIBUTE_CODE))->toBe($this->cityB);
});

it('mueve los leads abiertos de la persona al cambiar cliente_ciudad, pero no los cerrados', function () {
    $personId = makePerson();
    $repository = app(LeadRepository::class);

    $open = $repository->create([
        'title'                  => 'Lead abierto',
        'entity_type'            => 'leads',
        'person_id'              => $personId,
        'lead_pipeline_id'       => $this->cityA,
        'lead_pipeline_stage_id' => stageIdIn($this->cityA, 'no-atendido'),
    ]);

    $closed = $repository->create([
        'title'                  => 'Lead entregado',
        'entity_type'            => 'leads',
        'person_id'              => $personId,
        'lead_pipeline_id'       => $this->cityA,
        'lead_pipeline_stage_id' => stageIdIn($this->cityA, 'pedidos-entregados'),
    ]);

    // "Cliente sin interés" y "Otros servicios" también cuentan como cerradas.
    $discarded = $repository->create([
        'title'                  => 'Lead sin interes',
        'entity_type'            => 'leads',
        'person_id'              => $personId,
        'lead_pipeline_id'       => $this->cityA,
        'lead_pipeline_stage_id' => stageIdIn($this->cityA, 'cliente-sin-inters'),
    ]);

    $other = $repository->create([
        'title'                  => 'Lead otros servicios',
        'entity_type'            => 'leads',
        'person_id'              => $personId,
        'lead_pipeline_id'       => $this->cityA,
        'lead_pipeline_stage_id' => stageIdIn($this->cityA, 'otros-servicios'),
    ]);

    app(PersonRepository::class)->update([
        'entity_type'                                => 'persons',
        CitySyncService::PERSON_CITY_ATTRIBUTE_CODE  => $this->cityB,
    ], $personId);

    $open->refresh();
    $closed->refresh();
    $discarded->refresh();
    $other->refresh();

    expect((int) $open->lead_pipeline_id)->toBe($this->cityB)
        ->and((int) $open->lead_pipeline_stage_id)->toBe(stageIdIn($this->cityB, 'no-atendido'))
        ->and(cityValueOf('leads', $open->id, CitySyncService::LEAD_CITY_ATTRIBUTE_CODE))->toBe($this->cityB)
        ->and((int) $closed->lead_pipeline_id)->toBe($this->cityA)
        ->and((int) $discarded->lead_pipeline_id)->toBe($this->cityA)
        ->and((int) $other->lead_pipeline_id)->toBe($this->cityA);
});
