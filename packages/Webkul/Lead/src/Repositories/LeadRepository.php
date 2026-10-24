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

        $data = $this->sinCambioDeAsesor($data);

        if (empty($data['user_id'])) {
            // El asesor por defecto configurado gana sobre quien lo crea: todo
            // lead nuevo sin propietario elegido va a esa persona.
            $assignedId = $this->asesorPorDefecto()
                ?? auth()->guard('user')->id()
                ?? auth()->id();

            if (! $assignedId) {
                $userRepo = app(\Webkul\User\Repositories\UserRepository::class);
                $fallbackUser = $userRepo->resetModel()->where('status', 1)->orderBy('id')->first();
                if ($fallbackUser) {
                    $assignedId = $fallbackUser->id;
                }
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
        $data = $this->sinCambioDeAsesor($data);

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

            // Ganado y perdido por `code`, con las mismas listas que el tablero
            // (config/reporting.php). La heurística por nombre de abajo no
            // reconocía «Cierre exitoso» ni «Desistimiento», y closed_at
            // quedaba siempre vacío.
            $isWon = in_array($code, (array) config('reporting.stages.won', []), true)
                || ($code === 'won') || str_contains($name, 'ganado') || str_contains($name, 'entregado');
            $isLost = in_array($code, (array) config('reporting.stages.lost', []), true)
                || ($code === 'lost') || str_contains($name, 'perdido') || str_contains($name, 'cancelado');
            $isConfirmed = ($code === 'pedidos-confirmado') || str_contains($name, 'confirmado');

            $currentLead = $this->find($id);

            if ($isWon || $isLost) {
                $data['closed_at'] = $data['closed_at'] ?? Carbon::now();
            } else {
                $data['closed_at'] = null;
            }

            if ($isConfirmed && ! $currentLead?->confirmed_at) {
                $data['confirmed_at'] = Carbon::now();
            }

            // first_stage_change_at lo fija el modelo Lead al guardar (booted),
            // para cualquier camino que cambie la etapa.
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
                // Always (re)compute the line amount = price × quantity so it stays
                // consistent on edits (the create flow did this, the update didn't).
                $productInputs['amount'] = (float) ($productInputs['price'] ?? 0) * (float) ($productInputs['quantity'] ?? 0);

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

        $this->applyLeadValueRule($lead, $data);

        return $lead;
    }

    /**
     * Keeps lead_value in sync with the products (Σ price × quantity). Only
     * Supervisores/Administrador may override it manually; for everyone else the
     * value is always derived from the products. A manual override (the
     * lead_value_is_manual flag) is preserved across later product edits until a
     * privileged user clears it. Runs only on saves that carry products, so it
     * never touches stage-only updates (e.g. kanban drag).
     */
    private function applyLeadValueRule($lead, array $data): void
    {
        if (! isset($data['products'])) {
            return;
        }

        $productSum = (float) $lead->products()->sum(DB::raw('price * quantity'));

        $canOverride = $this->userCanOverrideLeadValue();

        // A manual override requires an explicit signal (lead_value_is_manual) from
        // a privileged user, plus a submitted value. This avoids accidentally
        // freezing the value when products change but the field wasn't touched.
        $wantsManual = $canOverride
            && ! empty($data['lead_value_is_manual'])
            && array_key_exists('lead_value', $data)
            && $data['lead_value'] !== null
            && $data['lead_value'] !== '';

        if ($wantsManual) {
            $lead->lead_value = (float) $data['lead_value'];
            $lead->lead_value_is_manual = true;
        } elseif ($canOverride) {
            // Privileged user not requesting a manual override => back to auto.
            $lead->lead_value = $productSum;
            $lead->lead_value_is_manual = false;
        } elseif (! $lead->lead_value_is_manual) {
            // Non-privileged users can never override; recalc unless a supervisor
            // previously froze it as a manual override.
            $lead->lead_value = $productSum;
        }

        $lead->save();
    }

    /**
     * Whether the current user may override lead_value manually.
     */
    private function userCanOverrideLeadValue(): bool
    {
        $user = auth()->guard('user')->user();

        return $user && in_array(optional($user->role)->name, ['Supervisores', 'Administrador'], true);
    }

    /**
     * Usuario al que van los leads nuevos sin propietario
     * (`inmobiliaria.inmuebles.asesor_por_defecto`, el mismo de las derivaciones).
     *
     * Devuelve null si no está configurado o si el usuario ya no está activo:
     * asignar leads a una cuenta dada de baja los dejaría sin nadie que los vea.
     */
    protected function asesorPorDefecto(): ?int
    {
        $id = config('inmobiliaria.inmuebles.asesor_por_defecto');

        if (! $id) {
            return null;
        }

        $activo = app(\Webkul\User\Repositories\UserRepository::class)
            ->resetModel()
            ->where('id', $id)
            ->where('status', 1)
            ->exists();

        return $activo ? (int) $id : null;
    }

    /**
     * Quita `user_id` de los datos si el usuario no puede elegir el asesor.
     *
     * Solo Administrador y Supervisores asignan o cambian el asesor de un lead
     * (spec tablero-por-asesor, D15). Se filtra acá y no en cada controlador
     * porque por este método pasan todos los caminos —formulario, edición en
     * línea de la ficha, kanban— y un control en el front se saltea armando
     * el request a mano. Sin usuario logueado (agente, jobs) no se toca nada.
     */
    protected function sinCambioDeAsesor(array $data): array
    {
        if (array_key_exists('user_id', $data) && ! app(\Webkul\Admin\Helpers\Alcance::class)->veTotal()) {
            unset($data['user_id']);
        }

        return $data;
    }
}
