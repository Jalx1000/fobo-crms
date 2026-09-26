<?php

namespace Webkul\Lead\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Lead\Contracts\Product as ProductContract;
use Webkul\Product\Models\ProductProxy;

class Product extends Model implements ProductContract
{
    protected $table = 'lead_products';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'quantity',
        'price',
        'amount',
        'product_id',
        'lead_id',
    ];

    /**
     * El monto de la línea SIEMPRE es precio × cantidad.
     *
     * Estaba calculado en LeadRepository (create y update) y en
     * LeadController::addProduct, pero se medía el resultado y no cerraba: 20
     * filas quedaron con amount = 0 teniendo precio y cantidad cargados —15 de
     * ellas en septiembre—. Mientras el cálculo viva en los llamadores, cada
     * camino nuevo hacia lead_products es una oportunidad de olvidarlo.
     *
     * Duele porque el ranking de productos filtra con HAVING SUM(amount) > 0
     * (Reporting/Product.php): una línea con amount 0 no es un dato feo, es una
     * venta que desaparece del reporte.
     *
     * Acá el cálculo queda en el único lugar por el que pasan todos.
     */
    protected static function booted(): void
    {
        static::saving(function (self $producto) {
            $producto->amount = (float) $producto->price * (float) $producto->quantity;
        });
    }

    /**
     * Get the product owns the lead product.
     */
    public function product()
    {
        return $this->belongsTo(ProductProxy::modelClass());
    }

    /**
     * Get the lead that owns the lead product.
     */
    public function lead()
    {
        return $this->belongsTo(LeadProxy::modelClass());
    }

    /**
     * Get the customer full name.
     */
    public function getNameAttribute()
    {
        return $this->product->name;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $array = parent::toArray();

        $array['name'] = $this->name;

        return $array;
    }
}
