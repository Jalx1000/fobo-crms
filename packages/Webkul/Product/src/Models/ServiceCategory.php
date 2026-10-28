<?php

namespace Webkul\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Webkul\Product\Contracts\ServiceCategory as ServiceCategoryContract;

/**
 * Categoría transversal del servicio (Facial / Corporal / Depilación). Un
 * servicio puede estar en varias.
 */
class ServiceCategory extends Model implements ServiceCategoryContract
{
    protected $table = 'service_categories';

    protected $fillable = [
        'name',
        'slug',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(ProductProxy::modelClass(), 'product_service_category', 'service_category_id', 'product_id');
    }
}
