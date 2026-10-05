<?php

namespace Webkul\Lead\Repositories;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Admin\Support\VisibilidadLeads;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Core\Eloquent\Repository;
use Webkul\Lead\Contracts\Lead;
use Webkul\Lead\Services\CitySyncService;

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
                    VisibilidadLeads::aplicar($query);
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
            $person = $this->resolvePerson($data['person']);

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

        app(CitySyncService::class)->syncFromLead($lead);

        if (isset($data['products'])) {
            foreach ($data['products'] as $product) {
                /*
                 * Una fila sin producto elegido no es un producto.
                 *
                 * El formulario deja agregar filas vacías, y quitar el producto
                 * de una fila ya cargada la deja con product_id nulo. Sin este
                 * filtro el insert moría con "Column 'product_id' cannot be
                 * null": el lead NO se creaba y el asesor veía un 500 sin saber
                 * qué fila corregir. Se ignora la fila y el resto se guarda.
                 */
                if (empty($product['product_id'])) {
                    continue;
                }

                $this->productRepository->create(array_merge($product, [
                    'lead_id' => $lead->id,
                    'amount'  => (float) ($product['price'] ?? 0) * (float) ($product['quantity'] ?? 0),
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
        /**
         * Ciudad del lead ANTES del guardado. La necesita CitySyncService para saber
         * si el pipeline cambió en este request (y por tanto le gana al atributo
         * `Ciudad`) o si el que cambió fue el atributo (y arrastra al lead).
         */
        $previousPipelineId = (int) $this->find($id)?->lead_pipeline_id ?: null;

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
            $person = $this->resolvePerson($data['person']);

            $data['person_id'] = $person->id;
        }

        if (isset($data['lead_pipeline_stage_id'])) {
            $stage = $this->stageRepository->find($data['lead_pipeline_stage_id']);

            $name = strtolower($stage->name ?? '');
            $code = strtolower($stage->code ?? '');
            $isWon = ($code === 'won') || ($code === 'pedido-entregado') || str_contains($name, 'won') || str_contains($name, 'ganado') || str_contains($name, 'entregado');
            $isLost = ($code === 'lost') || ($code === 'pedidos-cancelado') || str_contains($name, 'lost') || str_contains($name, 'perdido') || str_contains($name, 'cancelado');
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

            /**
             * first_stage_change_at: primera salida de Prospecto (la etapa inicial).
             * Pegajoso: se fija una sola vez y nunca se sobrescribe ni se borra, para que
             * el "Tiempo en responder" sea inmune a reaperturas/movimientos accidentales.
             */
            if (($isWon || $isLost || $isConfirmed) && ! $currentLead?->first_stage_change_at) {
                $data['first_stage_change_at'] = Carbon::now();
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

            app(CitySyncService::class)->syncFromLead($lead, $previousPipelineId);

            return $lead;
        }

        $this->attributeValueRepository->save(array_merge($data, [
            'entity_id' => $lead->id,
        ]));

        app(CitySyncService::class)->syncFromLead($lead, $previousPipelineId);

        $previousProductIds = $lead->products()->pluck('id');

        if (isset($data['products'])) {
            foreach ($data['products'] as $productId => $productInputs) {
                /*
                 * Igual que al crear: una fila sin producto elegido se ignora.
                 * Sólo se filtran las filas NUEVAS —las que traen la clave
                 * "product_N"—; una existente que llegue sin product_id se deja
                 * pasar al update, que no toca la columna si no viene.
                 */
                if (Str::contains($productId, 'product_') && empty($productInputs['product_id'])) {
                    continue;
                }

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

        /*
         * Los productos sobrantes se borran SÓLO si el payload trajo una lista.
         *
         * Este bucle estaba fuera del `isset` de arriba, así que cualquier update
         * que no mandara `products` borraba todos los del lead. Lo hacían el
         * Kanban (updateStage manda sólo la etapa), el mass update y la API del
         * agente: arrastrar un pedido de columna vaciaba su contenido, sin error
         * y sin rastro.
         *
         * Medido: `lead_products` tenía 0 filas para 15 pedidos, y 13 de esos 15
         * estaban en una etapa distinta de la inicial. Los productos no faltaban
         * por falta de carga — los borraba el propio cambio de etapa.
         *
         * La semántica "el payload reemplaza la lista" se mantiene intacta: con
         * `products` presente se sincroniza, y un array vacío explícito sigue
         * vaciando la lista. Lo que cambia es que la AUSENCIA de la clave ya no
         * significa "borrá todo".
         */
        if (isset($data['products'])) {
            foreach ($previousProductIds as $productId) {
                $this->productRepository->delete($productId);
            }
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
     * The person a lead payload refers to, without ever duplicating a contact.
     *
     * An explicit id wins. Otherwise we match on the phone number before
     * creating anything: the WhatsApp agent posts leads with the contact inline
     * and no id, so creating unconditionally gave the same customer a second
     * person on their second order — and split their history between the two,
     * which is exactly what `get_pedidos` then failed to see.
     */
    protected function resolvePerson(array $person)
    {
        if (! empty($person['id'])) {
            return $this->personRepository->findOrFail($person['id']);
        }

        if (
            ! empty($person['contact_numbers'])
            && $existing = $this->personRepository->findByContactNumbers($person['contact_numbers'])
        ) {
            return $existing;
        }

        return $this->personRepository->create(array_merge($person, [
            'entity_type' => 'persons',
        ]));
    }
}
