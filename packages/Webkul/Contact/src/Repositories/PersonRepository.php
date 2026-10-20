<?php

namespace Webkul\Contact\Repositories;

use Illuminate\Container\Container;
// use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Contracts\Person;
use Webkul\Core\Eloquent\Repository;
use Webkul\Core\Services\PhoneNumber;
use Webkul\Lead\Services\CitySyncService;

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

        /*
         * El cliente que da de alta un distribuidor es suyo. Sin dueño no lo vería
         * nadie más que los encargados —él incluido—, y a nombre de otro sería
         * regalarle el cliente a otra empresa.
         */
        if ($yo = app(\Webkul\Core\Services\Distribuidores::class)->soloLoSuyo()) {
            $data['user_id'] = $yo;
        }

        $person = parent::create($data);

        $this->attributeValueRepository->save(array_merge($data, [
            'entity_id' => $person->id,
        ]));

        app(CitySyncService::class)->syncFromPerson($person);

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

            app(CitySyncService::class)->syncFromPerson($person);

            return $person;
        }

        $this->attributeValueRepository->save(array_merge($data, [
            'entity_id' => $person->id,
        ]));

        app(CitySyncService::class)->syncFromPerson($person);

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
     * The person that owns a phone number, or null.
     *
     * Numbers are stored free-form in the `contact_numbers` JSON column, so the
     * same line lives in the CRM as "76616013", "591 76616013" or
     * "+591 76616013". Matching on the raw string therefore misses, which is how
     * one WhatsApp number ended up with two persons and a split order history.
     *
     * Strategy: prefilter in SQL on the last digits (index-less but cheap and
     * selective), then confirm in PHP with the normalized comparison.
     */
    public function findByPhone(?string $phone)
    {
        $tail = PhoneNumber::tail($phone);

        // Too short to identify anybody — matching on it would merge strangers.
        if (strlen($tail) < 7) {
            return null;
        }

        $candidates = $this->model
            ->where('contact_numbers', 'like', '%'.$tail.'%')
            ->get();

        // The repository's builder is stateful; leaving this where() on it would
        // leak into the next query made through the same instance.
        $this->resetModel();

        return $candidates->first(fn ($person) => $this->hasPhone($person, $phone));
    }

    /**
     * The person owning any of the given contact numbers (the shape the API and
     * the contact form both post: [['label' => ..., 'value' => ...], ...]).
     */
    public function findByContactNumbers($numbers)
    {
        foreach ((array) $numbers as $number) {
            $value = is_array($number) ? ($number['value'] ?? null) : $number;

            if ($value && $person = $this->findByPhone($value)) {
                return $person;
            }
        }

        return null;
    }

    /**
     * Does this person answer at that number?
     */
    public function hasPhone($person, ?string $phone): bool
    {
        foreach ((array) $person->contact_numbers as $number) {
            $value = is_array($number) ? ($number['value'] ?? '') : $number;

            if (PhoneNumber::same($value, $phone)) {
                return true;
            }
        }

        return false;
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
    private function sanitizeRequestedPersonData(array $data, ?int $id = null): array
    {
        if (
            array_key_exists('organization_id', $data)
            && empty($data['organization_id'])
        ) {
            $data['organization_id'] = null;
        }

        if (isset($data['contact_numbers'])) {
            $data['contact_numbers'] = array_values(
                collect($data['contact_numbers'])->filter(fn ($number) => ! is_null($number['value'] ?? null))->toArray()
            );
        }

        /**
         * On partial updates (workflow actions, quick add forms) the identifying fields may be
         * absent from the payload, so fall back to the persisted person to avoid rebuilding an
         * empty key that would collide on the `unique_id` unique index.
         */
        $person = $id ? $this->find($id) : null;

        $uniqueIdParts = [
            array_key_exists('user_id', $data) ? $data['user_id'] : $person?->user_id,
            array_key_exists('organization_id', $data) ? $data['organization_id'] : $person?->organization_id,
            $data['emails'][0]['value'] ?? ($person?->emails[0]['value'] ?? null),
            $data['contact_numbers'][0]['value'] ?? ($person?->contact_numbers[0]['value'] ?? null),
        ];

        $uniqueId = implode('|', array_filter($uniqueIdParts));

        /**
         * MySQL allows many NULLs but only a single empty string in a unique index, so an
         * unidentifiable person must keep the column untouched instead of writing ''.
         */
        if ($uniqueId === '') {
            unset($data['unique_id']);
        } else {
            $data['unique_id'] = $uniqueId;
        }

        return $data;
    }
}
