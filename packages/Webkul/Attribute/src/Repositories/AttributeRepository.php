<?php

namespace Webkul\Attribute\Repositories;

use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Webkul\Core\Eloquent\Repository;

class AttributeRepository extends Repository
{
    /**
     * Create a new repository instance.
     *
     * @return void
     */
    public function __construct(
        protected AttributeOptionRepository $attributeOptionRepository,
        Container $container
    ) {
        parent::__construct($container);
    }

    /**
     * Specify Model class name
     *
     * @return mixed
     */
    public function model()
    {
        return 'Webkul\Attribute\Contracts\Attribute';
    }

    /**
     * @return \Webkul\Attribute\Contracts\Attribute
     */
    public function create(array $data)
    {
        $options = isset($data['options']) ? $data['options'] : [];

        $attribute = $this->model->create($data);

        if (in_array($attribute->type, ['select', 'multiselect', 'checkbox']) && count($options)) {
            $sortOrder = 1;

            foreach ($options as $optionInputs) {
                $this->attributeOptionRepository->create(array_merge([
                    'attribute_id' => $attribute->id,
                    'sort_order'   => $sortOrder++,
                ], $optionInputs));
            }
        }

        return $attribute;
    }

    /**
     * @param  int  $id
     * @param  string  $attribute
     * @return \Webkul\Attribute\Contracts\Attribute
     */
    public function update(array $data, $id, $attribute = 'id')
    {
        $attribute = $this->find($id);

        $attribute->update($data);

        if (! in_array($attribute->type, ['select', 'multiselect', 'checkbox'])) {
            return $attribute;
        }

        if (! isset($data['options'])) {
            return $attribute;
        }

        foreach ($data['options'] as $optionId => $optionInputs) {
            if (str_contains($optionId, 'option_')) {
                $this->attributeOptionRepository->create(array_merge([
                    'attribute_id' => $attribute->id,
                ], $optionInputs));
            } else {
                if (is_numeric($optionId)) {
                    $isDelete = $optionInputs['isDelete'] == 'true';

                    if ($isDelete) {
                        $this->attributeOptionRepository->delete($optionId);
                    } else {
                        $this->attributeOptionRepository->update($optionInputs, $optionId);
                    }
                }
            }
        }

        return $attribute;
    }

    /**
     * @param  string  $code
     * @return \Webkul\Attribute\Contracts\Attribute
     */
    /**
     * El atributo de un código, dentro de una entidad.
     *
     * `$entityType` es opcional por compatibilidad, pero pasarlo importa: el
     * mismo código puede existir en dos entidades. `nit` está en `persons` y en
     * `quotes`, y sin la entidad esta búsqueda devuelve siempre el primero que
     * encuentra - así una cotización terminaba leyendo el id del atributo de la
     * persona y devolviendo null para un valor que sí estaba guardado.
     *
     * La caché también se indexa por entidad, no solo por código, o el primero
     * en consultarse le fijaría la respuesta al otro para el resto del request.
     */
    public function getAttributeByCode($code, ?string $entityType = null)
    {
        static $attributes = [];

        $clave = $entityType ? $entityType.'.'.$code : $code;

        if (array_key_exists($clave, $attributes)) {
            return $attributes[$clave];
        }

        return $attributes[$clave] = $entityType
            ? $this->findOneWhere(['code' => $code, 'entity_type' => $entityType])
            : $this->findOneByField('code', $code);
    }

    /**
     * @param  int  $lookup
     * @param  string  $query
     * @param  array  $columns
     * @return array
     */
    public function getLookUpOptions($lookup, $query = '', $columns = [])
    {
        $lookup = config('attribute_lookups.'.$lookup);

        if (! count($columns)) {
            $columns = [($lookup['value_column'] ?? 'id').' as id', ($lookup['label_column'] ?? 'name').' as name'];
        }

        if (Str::contains($lookup['repository'], 'UserRepository')) {
            $userRepository = app($lookup['repository'])->where('status', 1);

            $currentUser = auth()->guard('user')->user();

            /*
            | Quien puede cambiar el asesor (Administrador y Supervisores, por
            | ROL: LeadRepository::sinCambioDeAsesor) elige entre todos, aunque
            | su vista sea «individual» (Bulmaro, 20/10/2026): si no, la lista
            | le ofrecía solo a sí mismo y no podía asignar a otro ejecutivo.
            */
            if ($currentUser && app(\Webkul\Admin\Helpers\Alcance::class)->veTotal()) {
                return $userRepository->where('users.name', 'like', '%'.urldecode($query).'%')->get();
            }

            // El encargado nacional (Bulmaro) reasigna solo entre ejecutivos comerciales.
            if ($currentUser && app(\Webkul\Admin\Helpers\Alcance::class)->esEncargado()) {
                return $userRepository
                    ->whereIn('users.id', \Webkul\Admin\Helpers\Alcance::ejecutivosActivos() ?: [0])
                    ->where('users.name', 'like', '%'.urldecode($query).'%')
                    ->get();
            }

            if ($currentUser?->view_permission === 'group') {
                $query = urldecode($query);

                $userIds = bouncer()->getAuthorizedUserIds();

                return $userRepository
                    ->when(! empty($userIds), fn ($queryBuilder) => $queryBuilder->whereIn('users.id', $userIds))
                    ->when(! empty($query), fn ($queryBuilder) => $queryBuilder->where('users.name', 'like', "%{$query}%"))
                    ->get();
            } elseif ($currentUser?->view_permission === 'individual') {
                // ->get(): sin él devolvía la consulta sin ejecutar (lista vacía).
                return $userRepository->where('users.id', $currentUser->id)->get();
            }

            return $userRepository->where('users.name', 'like', '%'.urldecode($query).'%')->get();
        }

        return app($lookup['repository'])->findWhere([
            [$lookup['label_column'] ?? 'name', 'like', '%'.urldecode($query).'%'],
        ], $columns);
    }

    /**
     * @param  string  $lookup
     * @param  int|array  $entityId
     * @param  array  $columns
     * @return mixed
     */
    public function getLookUpEntity($lookup, $entityId = null, $columns = [])
    {
        if (! $entityId) {
            return;
        }

        $lookup = config('attribute_lookups.'.$lookup);

        if (! count($columns)) {
            $columns = [($lookup['value_column'] ?? 'id').' as id', ($lookup['label_column'] ?? 'name').' as name'];
        }

        if (is_array($entityId)) {
            return app($lookup['repository'])->findWhereIn(
                'id',
                $entityId,
                $columns
            );
        } else {
            return app($lookup['repository'])->find($entityId, $columns);
        }
    }
}
