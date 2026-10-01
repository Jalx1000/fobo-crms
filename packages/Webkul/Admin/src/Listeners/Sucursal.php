<?php

namespace Webkul\Admin\Listeners;

use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;

/**
 * Propone la sucursal de una cotización a partir de la planta de su producto.
 *
 * La sucursal contesta «¿dónde lo retiro?», y esa respuesta ya está en el
 * producto: cada uno se fabrica en una de las tres plantas (`warehouse_id`, que
 * se carga en la ficha del producto). Pedírsela al asesor era pedirle que
 * copiara un dato que el CRM tiene.
 *
 * Solo la PROPONE: si la cotización ya trae sucursal no se toca, porque puede
 * haberse acordado despachar desde otra planta. Y si el producto no tiene
 * planta asignada, se deja vacía antes que inventar una dirección —mandar al
 * cliente a la fábrica equivocada es peor que no decirle nada—.
 */
class Sucursal
{
    public function __construct(
        protected AttributeRepository $attributes,
        protected AttributeValueRepository $values,
    ) {}

    public function desdeElProducto($quote): void
    {
        if (! $quote || $quote->sucursal) {
            return;
        }

        // Por consulta y no por relación: `QuoteItem` no tiene `product()`, y
        // agregarla solo para esto ataría el módulo Quote al de productos.
        $planta = \Webkul\Product\Models\ProductProxy::modelClass()::query()
            ->whereIn('id', $quote->items->pluck('product_id')->filter())
            ->whereNotNull('warehouse_id')
            ->orderByRaw('FIELD(id, '.($quote->items->pluck('product_id')->filter()->join(',') ?: '0').')')
            ->value('warehouse_id');

        if (! $planta) {
            return;
        }

        $attribute = $this->attributes
            ->findWhere(['entity_type' => 'quotes', 'code' => 'sucursal'])
            ->first();

        if (! $attribute) {
            return;
        }

        $this->values->save([
            'entity_type' => 'quotes',
            'entity_id'   => $quote->id,
            'sucursal'    => $planta,
        ], collect([$attribute]));
    }
}
