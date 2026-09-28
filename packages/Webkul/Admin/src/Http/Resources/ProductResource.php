<?php

namespace Webkul\Admin\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request)
    {
        /**
         * The CustomAttribute trait's attributesToArray() overwrites the real
         * table columns (name, sku, price, ...) with their EAV custom-attribute
         * value. When a product only has the column populated (and no matching
         * row in attribute_values), those keys come back as null. We restore the
         * real column values from the raw model attributes so the lookup can show
         * and select products correctly.
         */
        return array_merge($this->resource->attributesToArray(), [
            'id'          => $this->id,
            'sku'         => $this->resource->getRawOriginal('sku'),
            'name'        => $this->resource->getRawOriginal('name'),
            'description' => $this->resource->getRawOriginal('description'),
            'quantity'    => $this->resource->getRawOriginal('quantity'),
            'price'       => $this->resource->getRawOriginal('price'),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,

            /*
            | Variantes y entrega para el formulario de cotización: al elegir el
            | producto, la fila del ítem ofrece sus variantes y completa TR4,
            | código de barra, medida y unidad sin que el asesor los escriba.
            |
            | Van acá y no en un endpoint aparte porque el lookup ya trae el
            | producto: una segunda llamada por cada fila que se agrega sería
            | una espera visible al elegir.
            */
            'variantes' => $this->resource->relationLoaded('variantes')
                ? $this->resource->variantes->map(fn ($v) => [
                    'id'             => $v->id,
                    'etiqueta'       => \Webkul\Product\Support\Variantes::etiqueta($this->resource, $v),
                    'valores'        => (object) ((array) $v->valores),
                    'codigo_tr4'     => $v->codigo_tr4,
                    'codigo_barra'   => $v->codigo_barra,
                    'medida'         => $v->medida,
                    'unidad'         => $v->unidad ?: 'PZA',
                    'nombre_erp'     => $v->nombre_erp,
                    // La ficha técnica de ESTA variante: el stretch film manual
                    // y el automático comparten ancho y espesor y difieren en
                    // peso, estiramiento y tipo de cono.
                    'caracteristicas' => (array) $v->caracteristicas,
                    'ficha'           => \Webkul\Product\Support\FichaTecnica::resumen($v->caracteristicas),
                    'tiempo_entrega' => $v->tiempo_entrega,
                ])->values()
                : [],

            'entrega' => [
                'tiempo'          => $this->resource->getRawOriginal('tiempo_entrega') ?: 'Inmediata',
                'lugar'           => $this->resource->getRawOriginal('lugar_entrega') ?: 'Puesto almacén cliente',
                'domicilio_desde' => $this->resource->getRawOriginal('entrega_domicilio_desde'),
            ],
        ]);
    }
}
