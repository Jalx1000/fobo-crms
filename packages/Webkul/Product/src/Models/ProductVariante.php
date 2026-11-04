<?php

namespace Webkul\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Contracts\ProductVariante as ProductVarianteContract;

class ProductVariante extends Model implements ProductVarianteContract
{
    protected $fillable = [
        'product_id',
        'valores',
        'codigo_tr4',
        'codigo_barra',
        'medida',
        'unidad',
        'nombre_erp',
        'caracteristicas',
        'tiempo_entrega',
        'orden',
    ];

    protected $casts = [
        'valores'         => 'array',
        'caracteristicas' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass());
    }

    /**
     * Lo que un ítem de cotización copia de la variante.
     *
     * Es la FOTO: se pega en `quote_items` al cotizar y no se vuelve a leer. Si
     * después alguien corrige el TR4, la cotización ya emitida no cambia - es
     * un documento, no una vista.
     */
    public function foto(): array
    {
        return [
            /*
            | El nombre con el que la linea sale en el PDF. Se toma el del ERP
            | («BSA MULTIUSO MAGIA VERDE 50L 10UN») porque es el que el cliente
            | reconoce de sus ordenes de compra; el nombre del producto en el
            | CRM es generico («Bolsas Magia Verde») y no dice que tamaño es.
            |
            | Null cuando la variante no lo tiene cargado: ahi manda el nombre
            | del producto, como antes.
            */
            'name'         => $this->nombre_erp ?: null,
            'codigo_tr4'   => $this->codigo_tr4,
            'codigo_barra' => $this->codigo_barra,
            'medida'       => $this->medida,
            'unidad'       => $this->unidad ?: 'PZA',
            'variante'     => (array) $this->valores,
        ];
    }
}
