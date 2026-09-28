<?php

namespace Webkul\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Contracts\ProductoResponsable as ProductoResponsableContract;
use Webkul\User\Models\UserProxy;

class ProductoResponsable extends Model implements ProductoResponsableContract
{
    protected $table = 'producto_responsables';

    protected $fillable = [
        'product_id',
        'ciudad',
        'user_id',
        'ultimo_asignado_at',
    ];

    protected $casts = [
        'ultimo_asignado_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProxy::modelClass());
    }

    /**
     * Si esta casilla es la de cobertura nacional («Bolivia»).
     */
    public function esNacional(): bool
    {
        return \Webkul\Product\Services\Responsables::esNacional((string) $this->ciudad);
    }
}
