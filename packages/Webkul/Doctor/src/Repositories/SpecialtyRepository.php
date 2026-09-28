<?php

namespace Webkul\Doctor\Repositories;

use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Webkul\Core\Eloquent\Repository;
use Webkul\Doctor\Contracts\Specialty as SpecialtyContract;

class SpecialtyRepository extends Repository
{
    protected $fieldSearchable = [
        'name',
        'slug',
    ];

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    public function model()
    {
        return SpecialtyContract::class;
    }

    public function fetchOrCreateByName(string $name)
    {
        $name = trim($name);

        $slug = Str::slug($name);

        $existing = $this->findOneWhere(['slug' => $slug]) ?: $this->findOneWhere(['name' => $name]);

        return $existing ?: parent::create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }
}
