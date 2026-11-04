<?php

namespace Webkul\Contact\Repositories;

use Illuminate\Container\Container;
use Illuminate\Support\Str;
// use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Contracts\Person;
use Webkul\Core\Eloquent\Repository;

class PersonRepository extends Repository
{
    /**
     * Searchable fields.
     */
    protected $fieldSearchable = [
        'name',
        'emails',
        'contact_numbers',
        'organization_id',
        'job_title',
        'organization.name',
        'user_id',
        'user.name',
    ];

    /**
     * Create a new repository instance.
     *
     * @return void
     */
    public function __construct(
        protected AttributeRepository $attributeRepository,
        protected AttributeValueRepository $attributeValueRepository,
        protected OrganizationRepository $organizationRepository,
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
        return Person::class;
    }

    /**
     * Create.
     *
     * @return \Webkul\Contact\Contracts\Person
     */
    public function create(array $data)
    {
        $data = $this->sanitizeRequestedPersonData($data);

        // Un ejecutivo crea contactos a su nombre (spec tablero-por-asesor, D13b).
        if ($userId = app(\Webkul\Admin\Helpers\Alcance::class)->userId()) {
            $data['user_id'] = $userId;
        }

        if (! empty($data['organization_name'])) {
            $organization = $this->fetchOrCreateOrganizationByName($data['organization_name']);

            $data['organization_id'] = $organization->id;
        } elseif (! empty($data['organization_id'])) {
            if (! $this->organizationRepository->find($data['organization_id'])) {
                $data['organization_id'] = null;
            }
        }

        if (isset($data['user_id'])) {
            $data['user_id'] = $data['user_id'] ?: null;

            if ($data['user_id'] && ! app(\Webkul\User\Repositories\UserRepository::class)->find($data['user_id'])) {
                $data['user_id'] = null;
            }
        }

        $person = parent::create($data);

        $this->attributeValueRepository->save(array_merge($data, [
            'entity_id' => $person->id,
        ]));

        return $person;
    }

    /**
     * Update.
     *
     * @return \Webkul\Contact\Contracts\Person
     */
    public function update(array $data, $id, $attributes = [])
    {
        $data = $this->sanitizeRequestedPersonData($data);

        /*
        | «Asesor Asignado» (spec tablero-por-asesor, D13b): solo Administrador y
        | Supervisores lo cambian; para un ejecutivo se ignora lo que venga.
        | Y se normaliza solo si vino: la edición en línea de la ficha manda un
        | campo por vez, y antes cualquier otro campo dejaba el contacto sin asesor.
        | El encargado nacional, solo a otro Ejecutivo Comercial (20/10/2026).
        */
        if (array_key_exists('user_id', $data) && ! app(\Webkul\Admin\Helpers\Alcance::class)->asesorPermitido($data['user_id'])) {
            unset($data['user_id']);
        } elseif (array_key_exists('user_id', $data)) {
            $data['user_id'] = empty($data['user_id']) ? null : $data['user_id'];
        }

        if (! empty($data['organization_name'])) {
            $organization = $this->fetchOrCreateOrganizationByName($data['organization_name']);

            $data['organization_id'] = $organization->id;

            unset($data['organization_name']);
        }

        $person = parent::update($data, $id);

        /**
         * If attributes are provided then only save the provided attributes and return.
         */
        if (! empty($attributes)) {
            $conditions = ['entity_type' => $data['entity_type']];

            if (isset($data['quick_add'])) {
                $conditions['quick_add'] = 1;
            }

            $attributes = $this->attributeRepository->where($conditions)
                ->whereIn('code', $attributes)
                ->get();

            $this->attributeValueRepository->save(array_merge($data, [
                'entity_id' => $person->id,
            ]), $attributes);

            return $person;
        }

        $this->attributeValueRepository->save(array_merge($data, [
            'entity_id' => $person->id,
        ]));

        return $person;
    }

    /*
    public function delete($id)
    {
        $person = $this->findOrFail($id);

        DB::transaction(function () use ($person, $id) {
            $this->attributeValueRepository->deleteWhere([
                'entity_id'   => $id,
                'entity_type' => 'persons',
            ]);

            $person->delete();
        });
    }
    */

    /**
     * Retrieves customers count based on date.
     *
     * @return int
     */
    public function getCustomerCount($startDate, $endDate)
    {
        return $this
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get()
            ->count();
    }

    /**
     * Fetch or create an organization.
     */
    public function fetchOrCreateOrganizationByName(string $organizationName)
    {
        $organization = $this->organizationRepository->findOneWhere([
            'name' => $organizationName,
        ]);

        return $organization ?: $this->organizationRepository->create([
            'entity_type' => 'organizations',
            'name'        => $organizationName,
        ]);
    }

    /**
     * Sanitize requested person data and return the clean array.
     */
    private function sanitizeRequestedPersonData(array $data): array
    {
        if (
            array_key_exists('organization_id', $data)
            && empty($data['organization_id'])
        ) {
            $data['organization_id'] = null;
        }

        $uniqueIdParts = array_filter([
            $data['user_id'] ?? null,
            $data['organization_id'] ?? null,
            $data['emails'][0]['value'] ?? null,
        ]);

        if (isset($data['contact_numbers'])) {
            // values(): filter() preserves keys, so dropping an empty first row
            // would leave the array starting at index 1 and the lookup below
            // would fail on a person whose first phone field was left blank.
            $data['contact_numbers'] = collect($data['contact_numbers'])
                ->filter(fn ($number) => ! is_null($number['value'] ?? null))
                ->values()
                ->toArray();

            if (isset($data['contact_numbers'][0]['value'])) {
                $uniqueIdParts[] = $data['contact_numbers'][0]['value'];
            }
        }

        // unique_id exists to de-duplicate people by their identifying data, and
        // the column is UNIQUE. A contact with none of it - someone who wrote
        // from a channel that carries no phone or email, like Messenger - can
        // be de-duplicated against nothing, so it gets a value of its own
        // instead of colliding with the next such contact on an empty string.
        //
        // The owner and the organization do not identify a person: two
        // phone-less Messenger contacts of the same advisor would otherwise
        // both get "256" and the second one would fail to register.
        $identifica = filled($data['emails'][0]['value'] ?? null)
            || filled($data['contact_numbers'][0]['value'] ?? null);

        $data['unique_id'] = $identifica
            ? implode('|', $uniqueIdParts)
            : (string) Str::uuid();

        return $data;
    }
}
