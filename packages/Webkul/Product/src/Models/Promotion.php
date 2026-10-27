<?php

namespace Webkul\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Contracts\Promotion as PromotionContract;

/**
 * Promoción mensual de un servicio: precio promocional y/o % de descuento, con
 * vigencia (valid_from..valid_to) y opcionalmente por sucursal. Los precios de
 * Tessa cambian cada mes; el precio base vive en products.price.
 */
class Promotion extends Model implements PromotionContract
{
    protected $table = 'promotions';

    protected $fillable = [
        'product_id',
        'branch_id',
        'name',
        'price',
        'discount_percent',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    protected $casts = [
        'price'            => 'decimal:4',
        'discount_percent' => 'decimal:2',
        'valid_from'       => 'date',
        'valid_to'         => 'date',
        'is_active'        => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(\Webkul\Contact\Models\BranchProxy::modelClass(), 'branch_id');
    }

    /**
     * Precio efectivo de la promo: el precio promocional si está seteado, o el
     * precio base menos el % de descuento. Null si no alcanza para calcularlo.
     */
    public function effectivePrice(?float $basePrice = null): ?float
    {
        if ($this->price !== null) {
            return (float) $this->price;
        }

        if ($this->discount_percent !== null && $basePrice !== null) {
            return round($basePrice * (1 - ((float) $this->discount_percent / 100)), 2);
        }

        return null;
    }
}
