<?php

namespace Webkul\Lead\Repositories;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Core\Eloquent\Repository;
use Webkul\Lead\Contracts\Lead;

class LeadRepository extends Repository
{
    /**
     * Searchable fields.
     */
    protected $fieldSearchable = [
        'title',
        'lead_value',
        'status',
        'user_id',
        'user.name',
        'person_id',
        'person.name',
        'lead_source_id',
        'lead_type_id',
        'lead_pipeline_id',
        'lead_pipeline_stage_id',
        'created_at',
        'closed_at',
        'expected_close_date',
    ];

    /**
     * Create a new repository instance.
     *
     * @return void
     */
    public function __construct(
        protected StageRepository $stageRepository,
        protected PersonRepository $personRepository,
        protected ProductRepository $productRepository,
        protected AttributeRepository $attributeRepository,
        protected AttributeValueRepository $attributeValueRepository,
        Container $container
    ) {
        parent::__construct($container);
    }

    /**
     * Specify model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Lead::class;
    }

    /**
     * Get leads query.
     *
     * @param  int  $pipelineId
     * @param  int  $pipelineStageId
     * @param  string  $term
     * @param  string  $createdAtRange
     * @return mixed
     */
    public function getLeadsQuery($pipelineId, $pipelineStageId, $term, $createdAtRange)
    {
        return $this->with([
            'attribute_values',
            'pipeline',
            'stage',
        ])->scopeQuery(function ($query) use ($pipelineId, $pipelineStageId, $term, $createdAtRange) {
            return $query->select(
                'leads.id as id',
                'leads.created_at as created_at',
                'title',
                'lead_value',
                'persons.name as person_name',
                'leads.person_id as person_id',
                'lead_pipelines.id as lead_pipeline_id',
                'lead_pipeline_stages.name as status',
                'lead_pipeline_stages.id as lead_pipeline_stage_id'
            )
                ->addSelect(DB::raw('DATEDIFF('.DB::getTablePrefix().'leads.created_at + INTERVAL lead_pipelines.rotten_days DAY, now()) as rotten_days'))
                ->leftJoin('persons', 'leads.person_id', '=', 'persons.id')
                ->leftJoin('lead_pipelines', 'leads.lead_pipeline_id', '=', 'lead_pipelines.id')
                ->leftJoin('lead_pipeline_stages', 'leads.lead_pipeline_stage_id', '=', 'lead_pipeline_stages.id')
                ->where('title', 'like', "%$term%")
                ->where('leads.lead_pipeline_id', $pipelineId)
                ->where('leads.lead_pipeline_stage_id', $pipelineStageId)
                ->when($createdAtRange, function ($query) use ($createdAtRange) {
                    return $query->whereBetween('leads.created_at', $createdAtRange);
                })
                ->where(function ($query) {
                    if ($userIds = bouncer()->getAuthorizedUserIds()) {
                        $query->whereIn('leads.user_id', $userIds);
                    }
                });
        });
    }

    /**
     * Create.
     *
     * @return \Webkul\Lead\Contracts\Lead
     */
    public function create(array $data)
    {
        // Sanitize foreign key values
        $foreignKeys = ['lead_source_id', 'lead_type_id', 'user_id'];
        foreach ($foreignKeys as $key) {
            if (array_key_exists($key, $data) && empty($data[$key])) {
                $data[$key] = null;
            }
        }

        /**
         * If a person is provided, create or update the person and set the `person_id`.
         */
        if (isset($data['person'])) {
            if (! empty($data['person']['id'])) {
                $person = $this->personRepository->findOrFail($data['person']['id']);
            } else {
                $person = $this->personRepository->create(array_merge($data['person'], [
                    'entity_type' => 'persons',
                ]));
            }

            $data['person_id'] = $person->id;
        }

        if (empty($data['expected_close_date'])) {
            $data['expected_close_date'] = null;
        }

        if (empty($data['user_id'])) {
            $assignedId = null;

            if (auth()->guard('user')->check()) {
                $assignedId = auth()->guard('user')->user()->id;
            } elseif (auth()->check()) {
                $assignedId = auth()->id();
            }

            if (! $assignedId) {
                // Sin usuario en contexto (p.ej. la sync SMD/Dropbox por cron): el lead
                // queda en la cuenta "Sin asignar" (no-admin), para que el tablero
                // por-vendedor no lo oculte. Antes caía en el primer usuario activo
                // (= admin id 1), lo que invisibilizaba estos leads en los reportes.
                $userRepo = app(\Webkul\User\Repositories\UserRepository::class);
                $unassigned = $userRepo->resetModel()
                    ->where('email', config('dashboard.unassigned_user.email'))
                    ->first();

                // Si la cuenta no existe en este entorno, se deja user_id nulo a
                // propósito (no se cae al admin).
                $assignedId = $unassigned?->id;
            }

            if ($assignedId) {
                $data['user_id'] = $assignedId;
            }
        }

        $lead = parent::create(array_merge([
            'lead_pipeline_id'       => 1,
            'lead_pipeline_stage_id' => 1,
        ], $data));

        // $allData = $this->all();
        // var_dump($data);
        // exit();

        $this->attributeValueRepository->save(array_merge($data, [
            'entity_id' => $lead->id,
        ]));

        if (isset($data['products'])) {
            foreach ($data['products'] as $product) {
                $this->productRepository->create(array_merge($product, [
                    'lead_id' => $lead->id,
                    'amount'  => $product['price'] * $product['quantity'],
                ]));
            }
        }

        $this->applyLeadValueRule($lead, $data);

        return $lead;
    }

    /**
     * Update.
     *
     * @param  int  $id
     * @param  array|\Illuminate\Database\Eloquent\Collection  $attributes
     * @return \Webkul\Lead\Contracts\Lead
     */
    public function update(array $data, $id, $attributes = [])
    {
        // Sanitize foreign key values
        $foreignKeys = ['lead_source_id', 'lead_type_id', 'user_id'];
        foreach ($foreignKeys as $key) {
            if (array_key_exists($key, $data) && empty($data[$key])) {
                $data[$key] = null;
            }
        }

        /**
         * If a person is provided, create or update the person and set the `person_id`.
         * Be cautious, as a lead can be updated without providing person data.
         * For example, in the lead Kanban section, when switching stages, only the stage will be updated.
         */
        if (isset($data['person'])) {
            if (! empty($data['person']['id'])) {
                $person = $this->personRepository->findOrFail($data['person']['id']);
            } else {
                $person = $this->personRepository->create(array_merge($data['person'], [
                    'entity_type' => 'persons',
                ]));
            }

            $data['person_id'] = $person->id;
        }

        if (isset($data['lead_pipeline_stage_id'])) {
            $stage = $this->stageRepository->find($data['lead_pipeline_stage_id']);

            $name = strtolower($stage->name ?? '');
            $code = strtolower($stage->code ?? '');
            $isWon = ($code === 'won') || str_contains($name, 'won') || str_contains($name, 'ganado');
            $isLost = ($code === 'lost') || str_contains($name, 'lost') || str_contains($name, 'perdido');

            if ($isWon || $isLost) {
                $data['closed_at'] = $data['closed_at'] ?? Carbon::now();
            } else {
                // Respeta un closed_at explícito: las etapas propias de la clínica
                // ("Cita Cancelada") no se llaman won/lost, y forzar null borraba el
                // cierre que manda el sync de SMD (741 cancelaciones sin closed_at,
                // AUD-05). Sin closed_at explícito, mover a otra etapa reabre.
                $data['closed_at'] = $data['closed_at'] ?? null;
            }
        }

        if (empty($data['expected_close_date'])) {
            $data['expected_close_date'] = null;
        }

        $lead = parent::update($data, $id);

        /**
         * If attributes are provided, only save the provided attributes and return.
         * A collection of attributes may also be provided, which will be treated as valid,
         * regardless of whether it is empty or not.
         */
        if (! empty($attributes)) {
            /**
             * If attributes are provided as an array, then fetch the attributes from the database;
             * otherwise, use the provided collection of attributes.
             */
            if (is_array($attributes)) {
                $conditions = ['entity_type' => $data['entity_type']];

                if (isset($data['quick_add'])) {
                    $conditions['quick_add'] = 1;
                }

                $attributes = $this->attributeRepository->where($conditions)
                    ->whereIn('code', $attributes)
                    ->get();
            }

            $this->attributeValueRepository->save(array_merge($data, [
                'entity_id' => $lead->id,
            ]), $attributes);

            return $lead;
        }

        $this->attributeValueRepository->save(array_merge($data, [
            'entity_id' => $lead->id,
        ]));

        $previousProductIds = $lead->products()->pluck('id');

        if (isset($data['products'])) {
            foreach ($data['products'] as $productId => $productInputs) {
                if (Str::contains($productId, 'product_')) {
                    $this->productRepository->create(array_merge([
                        'lead_id' => $lead->id,
                    ], $productInputs));
                } else {
                    if (is_numeric($index = $previousProductIds->search($productId))) {
                        $previousProductIds->forget($index);
                    }

                    $this->productRepository->update($productInputs, $productId);
                }
            }
        }

        foreach ($previousProductIds as $productId) {
            $this->productRepository->delete($productId);
        }

        $this->applyLeadValueRule($lead->refresh(), $data);

        return $lead;
    }

    /**
     * Keeps lead_value consistent with the lead's products.
     *
     * Rule (requested by the business):
     *   - lead_value mirrors the sum of the lead's product amounts by default.
     *   - Only an Administrator may set a manual value that differs from that sum
     *     (override). The override is flagged via lead_value_is_manual so a later
     *     edit by a non-admin won't silently wipe it.
     */
    protected function applyLeadValueRule(\Webkul\Lead\Contracts\Lead $lead, array $data): void
    {
        $productsSum = (float) $lead->products()->sum('amount');

        $submitted = array_key_exists('lead_value', $data) && $data['lead_value'] !== ''
            ? (float) $data['lead_value']
            : null;

        $isAdmin = $this->currentUserIsAdministrator();

        if ($isAdmin && $submitted !== null && $submitted != $productsSum) {
            // Administrator deliberately set a value different from the products sum.
            $lead->lead_value = $submitted;
            $lead->lead_value_is_manual = true;
        } elseif ($lead->lead_value_is_manual && ! $isAdmin) {
            // A non-admin must not override an existing manual value set by an admin.
            return;
        } else {
            // Default: the value reflects the sum of the lead's products.
            $lead->lead_value = $productsSum;
            $lead->lead_value_is_manual = false;
        }

        $lead->save();
    }

    /**
     * Whether the currently authenticated user has an Administrator role.
     * Covers both "Administrator" and "Administrador". No auth (imports, CLI) => false.
     */
    protected function currentUserIsAdministrator(): bool
    {
        $user = auth()->guard('user')->user();

        $roleName = $user?->role?->name;

        return $roleName ? str_starts_with(strtolower($roleName), 'admin') : false;
    }
}
