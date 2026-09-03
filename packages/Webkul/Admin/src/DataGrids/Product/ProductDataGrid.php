<?php

namespace Webkul\Admin\DataGrids\Product;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DataGrid\DataGrid;
use Webkul\Product\Repositories\ProductRepository;

class ProductDataGrid extends DataGrid
{
    /**
     * Default sort column of datagrid.
     *
     * @var ?string
     */
    protected $sortColumn = 'id';

    /**
     * Create data grid instance.
     */
    public function __construct(
        protected AttributeRepository $attributeRepository,
        protected ProductRepository $productRepository,
    ) {}

    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        if (request()->query('sort') && isset(request()->query('sort')['column']) && request()->query('sort')['column'] == 'ciudad_producto_sucursal') {
            $params = request()->query();
            $params['sort']['column'] = 'id';
            $params['sort']['order'] = 'desc';
            request()->merge($params);
        }

        $tablePrefix = DB::getTablePrefix();

        $queryBuilder = DB::table('products')
            ->leftJoin('attribute_values as precio_promocion_val', function ($join) {
                $join->on('products.id', '=', 'precio_promocion_val.entity_id')
                    ->where('precio_promocion_val.entity_type', '=', 'products')
                    ->where('precio_promocion_val.attribute_id', '=', function ($query) {
                        $query->select('id')->from('attributes')->where('code', 'precio_promocion')->where('entity_type', 'products')->limit(1);
                    });
            })
            ->select(
                'products.id',
                'products.sku',
                'products.name',
                'products.price',
                DB::raw('COALESCE('.$tablePrefix.'precio_promocion_val.integer_value, '.$tablePrefix.'precio_promocion_val.float_value, '.$tablePrefix.'precio_promocion_val.text_value) as precio_promocion')
            )
            ->groupBy('products.id');

        $this->addFilter('id', 'products.id');
        $this->addFilter('sku', 'products.sku');
        $this->addFilter('name', 'products.name');
        $this->addFilter('price', 'products.price');
        $this->addFilter('precio_promocion', DB::raw('COALESCE('.$tablePrefix.'precio_promocion_val.integer_value, '.$tablePrefix.'precio_promocion_val.float_value, '.$tablePrefix.'precio_promocion_val.text_value)'));

        return $queryBuilder;
    }

    /**
     * Add columns.
     */
    public function prepareColumns(): void
    {
        $this->addColumn([
            'index'      => 'sku',
            'label'      => trans('admin::app.products.index.datagrid.sku'),
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'      => 'name',
            'label'      => trans('admin::app.products.index.datagrid.name'),
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
        ]);

        $this->addColumn([
            'index'      => 'price',
            'label'      => trans('admin::app.products.index.datagrid.price'),
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
            'closure'    => fn ($row) => core()->formatBasePrice($row->price, 2),
        ]);

        $this->addColumn([
            'index'      => 'precio_promocion',
            'label'      => trans('admin::app.products.index.datagrid.precio_promocion'),
            'type'       => 'string',
            'sortable'   => true,
            'searchable' => true,
            'filterable' => true,
            'closure'    => fn ($row) => $row->precio_promocion ? core()->formatBasePrice($row->precio_promocion, 2) : '--',
        ]);

        $skippedCodes = ['sku', 'name', 'price', 'precio_promocion'];

        $attributes = $this->attributeRepository->findWhere(['entity_type' => 'products']);

        foreach ($attributes as $attribute) {
            if (in_array($attribute->code, $skippedCodes)) {
                continue;
            }

            $this->addColumn([
                'index'      => $attribute->code,
                'label'      => $attribute->name,
                'type'       => 'string',
                'visibility' => false,
                'closure'    => function ($row) use ($attribute) {
                    static $product;

                    if (! $product || $product->id != $row->id) {
                        $product = $this->productRepository->find($row->id);
                    }

                    if (! $product) {
                        return '';
                    }

                    $value = $product->getCustomAttributeValue($attribute);

                    if (is_null($value) || $value === '') {
                        return '';
                    }

                    if ($attribute->type === 'select') {
                        return $attribute->options->where('id', $value)->first()?->name ?? $value;
                    }

                    if ($attribute->type === 'lookup') {
                        $lookUpEntity = config('attribute_lookups.'.$attribute->lookup_type);

                        if (! $lookUpEntity) {
                            // Fallback: resolve from attribute_options (select-like behavior)
                            return $attribute->options->where('id', (int) $value)->first()?->name ?? $value;
                        }

                        $record = app($lookUpEntity['repository'])->find($value);

                        return $record->{$lookUpEntity['label_column'] ?? 'name'} ?? $value;
                    }

                    return $value;
                },
            ]);
        }
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('products.view')) {
            $this->addAction([
                'index'  => 'view',
                'icon'   => 'icon-eye',
                'title'  => trans('admin::app.products.index.datagrid.view'),
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.products.view', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('products.edit')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.products.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => fn ($row) => route('admin.products.edit', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('products.delete')) {
            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.products.index.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => fn ($row) => route('admin.products.delete', $row->id),
            ]);
        }
    }

    /**
     * Prepare mass actions.
     */
    public function prepareMassActions(): void
    {
        $this->addMassAction([
            'icon'   => 'icon-delete',
            'title'  => trans('admin::app.products.index.datagrid.delete'),
            'method' => 'POST',
            'url'    => route('admin.products.mass_delete'),
        ]);
    }

    /**
     * Process requested sorting.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function processRequestedSorting($requestedSort)
    {
        if (isset($requestedSort['column']) && $requestedSort['column'] === 'ciudad_producto_sucursal') {
            $requestedSort['column'] = 'id';
        }

        return parent::processRequestedSorting($requestedSort);
    }
}
