<?php

namespace Webkul\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Activity\Traits\LogsActivity;
use Webkul\Attribute\Traits\CustomAttribute;
use Webkul\Doctor\Models\SpecialtyProxy;
use Webkul\Product\Contracts\Product as ProductContract;
use Webkul\Tag\Models\TagProxy;
use Webkul\Warehouse\Models\LocationProxy;
use Webkul\Warehouse\Models\WarehouseProxy;

class Product extends Model implements ProductContract
{
    use CustomAttribute, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'sku',
        'specialty_id',
        'description',
        'frequency_text',
        'min_interval_days',
        'recommended_sessions_min',
        'recommended_sessions_max',
        'quantity',
        'price',
        'duration_minutes',
    ];

    protected $casts = [
        'duration_minutes'         => 'integer',
        'min_interval_days'        => 'integer',
        'recommended_sessions_min' => 'integer',
        'recommended_sessions_max' => 'integer',
    ];

    /**
     * Get the product warehouses that owns the product.
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(WarehouseProxy::modelClass(), 'product_inventories');
    }

    /**
     * Get the product locations that owns the product.
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(LocationProxy::modelClass(), 'product_inventories', 'product_id', 'warehouse_location_id');
    }

    /**
     * Get the product inventories that owns the product.
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(ProductInventoryProxy::modelClass());
    }

    /**
     * The specialty (service category / treatment line) the product belongs to.
     */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(SpecialtyProxy::modelClass(), 'specialty_id');
    }

    /**
     * The tags that belong to the Products.
     */
    public function tags()
    {
        return $this->belongsToMany(TagProxy::modelClass(), 'product_tags');
    }

    /**
     * Get the activities.
     */
    public function activities()
    {
        return $this->belongsToMany(ActivityProxy::modelClass(), 'product_activities');
    }

    /**
     * Zonas / variantes del servicio (cada una con su duración y precio).
     */
    public function zones(): HasMany
    {
        return $this->hasMany(ServiceZoneProxy::modelClass(), 'product_id');
    }

    /**
     * Categorías transversales del servicio (Facial / Corporal / Depilación).
     * Un servicio puede pertenecer a más de una, aparte de su línea (especialidad).
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategoryProxy::modelClass(), 'product_service_category', 'product_id', 'service_category_id');
    }

    /**
     * Promociones del servicio (con vigencia y, opcionalmente, por sucursal).
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(PromotionProxy::modelClass(), 'product_id');
    }

    /**
     * Promoción vigente hoy para este servicio en la sucursal dada (o global).
     * Prioriza la específica de la sucursal sobre la global.
     */
    public function activePromotion(?int $branchId = null): ?Promotion
    {
        $today = now()->toDateString();

        return $this->promotions()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($w) => $w->orWhere('branch_id', $branchId)))
            ->orderByRaw('branch_id IS NULL') // la de sucursal (branch_id no nulo) primero
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * Precio a cobrar hoy: el de la promo vigente si existe, o el precio base.
     */
    public function currentPrice(?int $branchId = null): ?float
    {
        $base = $this->price !== null ? (float) $this->price : null;

        $promo = $this->activePromotion($branchId);

        return $promo?->effectivePrice($base) ?? $base;
    }
}
