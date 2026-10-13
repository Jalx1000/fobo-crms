<?php

namespace Webkul\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Warehouse\Contracts\Location as LocationContract;

class Location extends Model implements LocationContract
{
    /**
     * The table associated with the model.
     */
    protected $table = 'warehouse_locations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'maps_url',
        'warehouse_id',
    ];

    /**
     * Get the warehouse that owns the location.
     */
    public function warehouse()
    {
        return $this->belongsTo(WarehouseProxy::modelClass());
    }

    /**
     * Los productos que se fabrican en esta ubicación (planta).
     */
    public function productos()
    {
        return $this->hasMany(\Webkul\Product\Models\ProductProxy::modelClass(), 'warehouse_location_id');
    }
}
