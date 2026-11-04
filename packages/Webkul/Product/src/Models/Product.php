<?php

namespace Webkul\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Activity\Traits\LogsActivity;
use Webkul\Attribute\Traits\CustomAttribute;
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
        'description',
        'quantity',
        'price',
        'caracteristicas',
        'ejes_precio',
        'ejes_identidad',
        'tiempo_entrega',
        'lugar_entrega',
        'warehouse_id',
        'warehouse_location_id',
        'entrega_domicilio_desde',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'caracteristicas' => 'array',
        'ejes_precio'     => 'array',
        'ejes_identidad'  => 'array',
    ];

    /**
     * Adjuntos del producto: imágenes, documentos y enlaces.
     *
     * Ordenados como el asesor los dejó, con la portada primero: es el orden en
     * que se envían por WhatsApp, y mandar la portada segunda haría que el
     * cliente vea primero un detalle en vez de la foto principal.
     */
    public function medias(): HasMany
    {
        return $this->hasMany(ProductMediaProxy::modelClass())
            ->orderByDesc('es_portada')
            ->orderBy('orden');
    }

    /**
     * Lista de precios: una fila por combinación de los ejes del producto.
     *
     * Vacía en la mayoría de los productos, que se venden al `price` fijo. Solo
     * la usan los que se cotizan por variante - Bolsas, Hules, Tapas.
     */
    public function precios(): HasMany
    {
        return $this->hasMany(ProductPriceProxy::modelClass())->orderBy('orden');
    }

    /**
     * La planta (sucursal) que lo fabrica y desde donde se despacha.
     *
     * Distinta de `warehouses()`, que es la relación de INVENTARIO: esa dice en
     * qué almacenes hay stock, esta de cuál sale el producto.
     */
    public function planta(): BelongsTo
    {
        return $this->belongsTo(\Webkul\Warehouse\Models\WarehouseProxy::modelClass(), 'warehouse_id');
    }

    /**
     * La ubicación de la planta dentro del almacén, con su enlace de Maps.
     *
     * Hay un solo almacén (Santa Cruz) y una ubicación por planta: el almacén
     * dice la ciudad, la ubicación dónde se retira este producto.
     */
    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(\Webkul\Warehouse\Models\LocationProxy::modelClass(), 'warehouse_location_id');
    }

    /**
     * Quién lo atiende en cada ciudad.
     */
    public function responsables(): HasMany
    {
        return $this->hasMany(ProductoResponsableProxy::modelClass());
    }

    /**
     * El asesor al que le toca este producto en una ciudad.
     *
     * Es una consulta, no una asignación: no mueve el turno. Sin ciudad, o sin
     * nadie cargado para esa ciudad (ni con cobertura nacional), devuelve null:
     * es mejor que la cotización quede sin asignar -y visible como tal- que
     * asignada a quien no corresponde.
     */
    public function responsableEn(?string $ciudad)
    {
        if (! $ciudad) {
            return null;
        }

        return app(\Webkul\Product\Services\Responsables::class)->candidatos($this, $ciudad)->first()?->user;
    }

    /**
     * Códigos por variante: TR4, código de barra, medida, unidad.
     */
    public function variantes(): HasMany
    {
        return $this->hasMany(ProductVarianteProxy::modelClass())->orderBy('orden');
    }

    /**
     * El precio depende de lo que el cliente elija, no del producto.
     *
     * Se deduce de la lista en vez de guardarse como bandera: una bandera en
     * true sin filas cargadas dejaría al agente sin precio y sin poder explicar
     * por qué, que es peor que no tener la bandera.
     */
    public function precioVariable(): bool
    {
        return $this->precios()->exists();
    }

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
}
