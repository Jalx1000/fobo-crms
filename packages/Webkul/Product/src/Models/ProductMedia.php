<?php

namespace Webkul\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Contracts\ProductMedia as ProductMediaContract;

class ProductMedia extends Model implements ProductMediaContract
{
    protected $table = 'product_medias';

    protected $fillable = [
        'product_id',
        'variante_id',
        'tipo',
        'path',
        'url',
        'orden',
        'es_portada',
    ];

    protected $casts = [
        'es_portada' => 'boolean',
        'orden'      => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass());
    }

    /**
     * Un enlace no tiene archivo; una imagen o un documento sí.
     */
    public function esArchivo(): bool
    {
        return $this->tipo !== 'enlace';
    }

    /**
     * A dónde apunta este adjunto, sea cual sea su tipo.
     *
     * Los archivos viven en el disco privado, así que se sirven por una ruta
     * autenticada en vez de una URL de storage: la galería de un producto no
     * debe ser visible para cualquiera que adivine el path.
     */
    public function getEnlaceAttribute(): ?string
    {
        return $this->esArchivo()
            ? ($this->path ? route('admin.products.medias.archivo', $this->id) : null)
            : $this->url;
    }

    /**
     * La variante a la que pertenece, o null si es un adjunto general del
     * producto.
     */
    public function variante()
    {
        return $this->belongsTo(ProductVarianteProxy::modelClass(), 'variante_id');
    }
}
