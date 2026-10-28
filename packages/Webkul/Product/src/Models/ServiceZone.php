<?php

namespace Webkul\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Product\Contracts\ServiceZone as ServiceZoneContract;

/**
 * Zona / variante de un servicio. Lleva la duración y el precio (depilación
 * axilas 30 min / Bs 70, piernas 30 min / Bs 280, HIFU rostro+cuello 90 min...).
 */
class ServiceZone extends Model implements ServiceZoneContract
{
    protected $table = 'service_zones';

    protected $fillable = [
        'product_id',
        'name',
        'duration_minutes',
        'price',
        'is_active',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'price'            => 'decimal:4',
        'is_active'        => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(PromotionProxy::modelClass(), 'service_zone_id');
    }

    /**
     * Promoción vigente hoy para esta zona: la específica de la zona, o una
     * promo del servicio (product_id de la zona, sin service_zone_id).
     */
    public function activePromotion(?int $branchId = null): ?Promotion
    {
        $today = now()->toDateString();

        return PromotionProxy::modelClass()::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($w) => $w->orWhere('branch_id', $branchId)))
            ->where(fn ($q) => $q->where('service_zone_id', $this->id)
                ->orWhere(fn ($w) => $w->whereNull('service_zone_id')->where('product_id', $this->product_id)))
            ->orderByRaw('service_zone_id IS NULL')  // la de zona primero
            ->orderByRaw('branch_id IS NULL')        // la de sucursal primero
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * Precio a cobrar hoy por esta zona: promo vigente o precio base de la zona.
     */
    public function currentPrice(?int $branchId = null): ?float
    {
        $base = $this->price !== null ? (float) $this->price : null;

        return $this->activePromotion($branchId)?->effectivePrice($base) ?? $base;
    }
}
