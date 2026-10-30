<?php

namespace Webkul\Doctor\Repositories;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Webkul\Core\Eloquent\Repository;
use Webkul\Doctor\Contracts\Specialty as SpecialtyContract;

class SpecialtyRepository extends Repository
{
    const CACHE_KEY = 'specialties_list';

    const CACHE_TTL = 86400; // 24 horas

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

    public function all($columns = ['*'])
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => parent::all($columns));
    }

    public function create(array $data)
    {
        $result = parent::create($data);
        Cache::forget(self::CACHE_KEY);

        return $result;
    }

    public function update(array $data, $id, $attribute = 'id')
    {
        $result = parent::update($data, $id, $attribute);
        Cache::forget(self::CACHE_KEY);

        return $result;
    }

    public function delete($id)
    {
        $result = parent::delete($id);
        Cache::forget(self::CACHE_KEY);

        return $result;
    }

    /**
     * Resuelve una especialidad a partir de un identificador flexible:
     * ID numérico, slug exacto o nombre (coincidencia parcial).
     *
     * @param  int|string  $identifier
     * @return \Webkul\Doctor\Contracts\Specialty|null
     */
    public function resolveByIdentifier($identifier)
    {
        if (is_string($identifier)) {
            $identifier = trim($identifier);
        }

        if ($identifier === '' || $identifier === null) {
            return null;
        }

        if (is_numeric($identifier)) {
            return $this->find((int) $identifier);
        }

        $slug = Str::slug($identifier);

        if ($bySlug = $this->findOneWhere(['slug' => $slug])) {
            return $bySlug;
        }

        return $this->getModel()->newQuery()
            ->where('name', 'like', '%'.$identifier.'%')
            ->first();
    }

    public function fetchOrCreateByName(string $name)
    {
        $name = trim($name);

        $slug = Str::slug($name);

        $existing = $this->findOneWhere(['slug' => $slug]) ?: $this->findOneWhere(['name' => $name]);

        if ($existing) {
            return $existing;
        }

        // create() ya invalida el cache
        return $this->create([
            'name' => $name,
            'slug' => $slug,
        ]);
    }
}
