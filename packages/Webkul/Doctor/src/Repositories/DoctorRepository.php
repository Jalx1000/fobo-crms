<?php

namespace Webkul\Doctor\Repositories;

use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use Webkul\Core\Eloquent\Repository;
use Webkul\Doctor\Contracts\Doctor as DoctorContract;
use Webkul\Doctor\Services\SpecialtyService;

class DoctorRepository extends Repository
{
    protected $fieldSearchable = [
        'number',
        'name',
        'title',
        'specialties.name',
    ];

    public function __construct(
        protected SpecialtyService $specialtyService,
        Container $container
    ) {
        parent::__construct($container);
    }

    public function model()
    {
        return DoctorContract::class;
    }

    public function create(array $data)
    {
        $data = $this->sanitize($data);

        if (! isset($data['unique_id']) && isset($data['name'])) {
            $data['unique_id'] = implode('|', array_filter([$data['number'] ?? null, $data['name'] ?? null]));
        }

        $specialtyIds = $this->resolveSpecialties($data);

        $doctor = parent::create(Arr::except($data, ['specialties', 'specialty_names']));

        if (! empty($specialtyIds)) {
            $doctor->specialties()->sync($specialtyIds);
        }

        return $doctor;
    }

    public function update(array $data, $id)
    {
        $data = $this->sanitize($data);

        // unique_id = _id del médico en ShareMeData: solo se escribe cuando llega
        // uno explícito (vinculación con SMD). Nunca se inventa ni se vacía al
        // editar: antes se pisaba con "número|nombre" y el doctor dejaba de
        // recibir sus citas de SMD.
        if (array_key_exists('unique_id', $data) && trim((string) $data['unique_id']) === '') {
            unset($data['unique_id']);
        }

        $specialtyIds = $this->resolveSpecialties($data);

        $doctor = parent::update(Arr::except($data, ['specialties', 'specialty_names']), $id);

        if (! is_null($specialtyIds)) {
            $doctor->specialties()->sync($specialtyIds);
        }

        return $doctor;
    }

    protected function sanitize(array $data): array
    {
        if (array_key_exists('number', $data)) {
            $data['number'] = trim((string) $data['number']);
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('title', $data)) {
            $data['title'] = trim((string) $data['title']);
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = trim((string) $data['email']);
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        return $data;
    }

    protected function resolveSpecialties(array $data): array
    {
        $specialties = $data['specialties'] ?? [];

        if (! is_array($specialties)) {
            $specialties = ($specialties !== '' && $specialties !== null)
                ? explode(',', (string) $specialties)
                : [];
        }

        return $this->specialtyService->resolveOrCreateMany(
            $specialties,
            $data['specialty_names'] ?? []
        );
    }
}
