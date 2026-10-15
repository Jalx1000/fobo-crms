<?php

namespace Webkul\Contact\Repositories;

use Illuminate\Container\Container;
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
        $data = $this->sanitizeRequestedPersonData($data, $id);

        $data['user_id'] = empty($data['user_id']) ? null : $data['user_id'];

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
    private function sanitizeRequestedPersonData(array $data, $ignoreId = null): array
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

        $data['unique_id'] = implode('|', $uniqueIdParts);

        if (isset($data['contact_numbers'])) {
            $data['contact_numbers'] = collect($data['contact_numbers'])->filter(fn ($number) => ! is_null($number['value']))->values()->toArray();

            // The filter can drop index 0, so only append when a number survives.
            if (! empty($data['contact_numbers'][0]['value'])) {
                $data['unique_id'] .= '|'.$data['contact_numbers'][0]['value'];
            }
        }

        if (
            array_key_exists('unique_id', $data)
            && empty($data['unique_id'])
        ) {
            unset($data['unique_id']);
        }

        // unique_id is a best-effort dedupe hint, not an identity: two people can
        // legitimately share a phone (family members) with no email to tell them
        // apart, which collapses unique_id to "|<phone>". Rather than let that
        // raise a raw 1062 on an otherwise valid contact, drop the hint so the
        // row saves with a NULL unique_id - the column is nullable and MySQL
        // allows many NULLs in a unique index.
        if (! empty($data['unique_id'])) {
            $collides = app($this->model())->newQuery()
                ->where('unique_id', $data['unique_id'])
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists();

            if ($collides) {
                unset($data['unique_id']);
            }
        }

        return $data;
    }
}
