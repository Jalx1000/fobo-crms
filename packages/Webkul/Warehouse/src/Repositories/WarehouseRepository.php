<?php

namespace Webkul\Warehouse\Repositories;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Core\Eloquent\Repository;
use Webkul\Warehouse\Contracts\Warehouse;
use Webkul\Warehouse\Services\SucursalTextoParser;

class WarehouseRepository extends Repository
{
    /**
     * Searchable fields.
     */
    protected $fieldSearchable = [
        'name',
        'contact_name',
        'contact_emails',
        'contact_numbers',
        'contact_address',
    ];

    /**
     * Create a new repository instance.
     *
     * @return void
     */
    public function __construct(
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
        return Warehouse::class;
    }

    /**
     * Create.
     *
     * @return \Webkul\Warehouse\Contracts\Warehouse
     */
    public function create(array $data)
    {
        $warehouse = parent::create($data);

        $this->attributeValueRepository->save(array_merge($data, [
            'entity_id' => $warehouse->id,
        ]));

        return $warehouse;
    }

    /**
     * Update.
     *
     * @param  int  $id
     * @param  array  $attribute
     * @return \Webkul\Warehouse\Contracts\Warehouse
     */
    public function update(array $data, $id, $attributes = [])
    {
        $warehouse = parent::update($data, $id);

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
                'entity_id' => $warehouse->id,
            ]), $attributes);

            return $warehouse;
        }

        $this->attributeValueRepository->save(array_merge($data, [
            'entity_id' => $warehouse->id,
        ]));

        return $warehouse;
    }

    /**
     * Sucursales en la forma magra que consume el agente de WhatsApp: solo
     * ciudad, dirección, horarios, mapa y teléfono.
     *
     * Una sucursal cuenta solo si es una ciudad del CRM (pipeline = ciudad):
     * así queda afuera "Todas", que es un comodín del catálogo de productos, sin
     * fijar su id. La ciudad sale con el nombre del pipeline ("Potosí", aunque la
     * sucursal se llame "Potosi").
     *
     * Solo lectura. Los campos vacíos van en null y la sucursal no se omite.
     *
     * @return array<int, array{ciudad: string, direccion: ?string, horarios: ?string, mapa_url: ?string, telefono: ?string}>
     */
    public function sucursalesParaAgente(?string $ciudad = null): array
    {
        $ciudades = DB::table('lead_pipelines')
            ->pluck('name')
            ->keyBy(fn ($nombre) => $this->normalizarCiudad($nombre));

        $filtro = $ciudad === null ? null : $this->normalizarCiudad($ciudad);
        $parser = new SucursalTextoParser;
        $sucursales = [];

        foreach ($this->model->newQuery()->orderBy('name')->get() as $warehouse) {
            $address = $warehouse->contact_address ?? [];

            $nombres = array_filter([
                $this->normalizarCiudad($warehouse->name),
                $this->normalizarCiudad($address['city'] ?? ''),
            ]);

            $nombreCiudad = collect($nombres)->map(fn ($n) => $ciudades->get($n))->filter()->first();

            if (! $nombreCiudad) {
                continue;
            }

            if ($filtro !== null && $this->normalizarCiudad($nombreCiudad) !== $filtro && ! in_array($filtro, $nombres, true)) {
                continue;
            }

            $sucursales[] = ['ciudad' => $nombreCiudad]
                + $parser->parse($address['address'] ?? null)
                + ['telefono' => $this->telefonoParaAgente($warehouse->contact_numbers ?? [])];
        }

        return $sucursales;
    }

    /**
     * "  POTOSÍ " → "potosi": sin tildes, mayúsculas ni espacios de más.
     */
    protected function normalizarCiudad(?string $nombre): string
    {
        return Str::squish(Str::lower(Str::ascii((string) $nombre)));
    }

    /**
     * Primer número cargado, con el prefijo de Bolivia una sola vez.
     */
    protected function telefonoParaAgente(array $numeros): ?string
    {
        foreach ($numeros as $numero) {
            $digitos = preg_replace('/\D/', '', (string) ($numero['value'] ?? ''));

            if ($digitos === '') {
                continue;
            }

            // Celulares y fijos de Bolivia tienen 8 dígitos: si sobran y empieza
            // por 591, el prefijo ya venía cargado.
            if (strlen($digitos) > 8 && str_starts_with($digitos, '591')) {
                $digitos = substr($digitos, 3);
            }

            return '+591 '.$digitos;
        }

        return null;
    }
}
