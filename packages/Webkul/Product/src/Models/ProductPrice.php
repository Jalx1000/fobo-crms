<?php

namespace Webkul\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Contracts\ProductPrice as ProductPriceContract;

class ProductPrice extends Model implements ProductPriceContract
{
    protected $fillable = [
        'product_id',
        'valores',
        'precio',
        'unidad',
        'minimo',
        'nota',
        'orden',
    ];

    protected $casts = [
        'valores' => 'array',
        'precio'  => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass());
    }

    /**
     * Si esta fila se cotiza o se deriva.
     *
     * Una fila sin precio no es una fila incompleta: es una combinación que
     * existe y que a propósito no se cotiza por chat (ver la migración).
     */
    public function cotiza(): bool
    {
        return $this->precio !== null;
    }

    /**
     * Cómo se nombra la combinación cuando hay que mostrarla o mencionarla.
     *
     * "50 L · HORECA". Se arma desde `valores` y no desde los ejes del producto
     * para que siga funcionando si alguien renombra un eje después de cargar la
     * lista: se muestra lo que se cargó, no lo que debería haberse cargado.
     */
    public function getEtiquetaAttribute(): string
    {
        return implode(' · ', array_filter((array) $this->valores, fn ($v) => $v !== null && $v !== ''));
    }
}
