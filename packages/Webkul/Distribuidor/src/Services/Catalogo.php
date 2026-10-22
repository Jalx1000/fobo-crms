<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Services\AlcanceCiudades;

/**
 * Qué puede vender una sucursal, y a qué precio.
 *
 * El atributo `ciudad_producto_sucursal` es un multiselect que se guarda como
 * string de ids separados por coma ("1,9"), no como filas. Por eso el filtro se
 * hace comparando contra la lista parseada y no con un `where` directo: un
 * `like '%1%'` matchearía también el 10 y el 11.
 *
 * Importa más de lo que parece: hay dos productos con el MISMO nombre y distinto
 * precio según la sucursal —el botellón de 20 L vale 8.000 en San Salvador de
 * Jujuy y 7.500 en Barrio Alto Comedero y Palpalá—. Un catálogo sin filtrar le
 * hace cobrar al distribuidor el precio de otra ciudad, y nadie se entera hasta
 * que cuadran la caja.
 */
class Catalogo
{
    protected const ATRIBUTO = 'ciudad_producto_sucursal';

    public function __construct(protected AttributeRepository $attributes) {}

    /**
     * Las sucursales entre las que puede elegir el Punto, cada una con la ciudad
     * (pipeline) que le corresponde ya resuelta.
     *
     * @return array<int, array{id:int, name:string, ciudad_id:?int}>
     */
    public function sucursales(): array
    {
        $todas = (int) config('distribuidor.sucursal_todas');

        return DB::table('warehouses')
            ->whereNot('id', $todas) // "Todas" no es un lugar donde pararse a vender.
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($w) => [
                'id'        => (int) $w->id,
                'name'      => (string) $w->name,
                'ciudad_id' => $this->ciudadDeSucursal((int) $w->id),
            ])
            ->all();
    }

    /**
     * La ciudad (pipeline) de una sucursal (warehouse).
     *
     * 🔴 El puente se hace por NOMBRE y no por id, y no es una comodidad: los
     * ids de las dos tablas se contradicen. `1` es Palpalá en `lead_pipelines`
     * y Barrio Alto Comedero en `warehouses`; Palpalá es 1 como ciudad y 9 como
     * sucursal. Tratarlos como intercambiables asigna el pedido a otra ciudad sin
     * producir ningún error.
     *
     * El modelo de datos es el que fuerza esto: los atributos custom se llaman
     * los tres "Ciudad" pero apuntan a tablas distintas —`Ciudad` (leads) y
     * `cliente_ciudad` (persons) a `lead_pipelines`, `ciudad_producto_sucursal`
     * (products) a `warehouses`—. Mientras eso siga así, el nombre es el único
     * dato que las dos tablas comparten.
     *
     * Vive acá, en un solo lugar y del lado del servidor, para que ninguna otra
     * parte del módulo improvise su propia versión de la equivalencia.
     */
    public function ciudadDeSucursal(int $warehouseId): ?int
    {
        $nombre = DB::table('warehouses')->where('id', $warehouseId)->value('name');

        if (! $nombre) {
            return null;
        }

        $buscado = $this->normalizar($nombre);

        $pipeline = DB::table('lead_pipelines')
            ->get(['id', 'name'])
            ->first(fn ($p) => $this->normalizar($p->name) === $buscado);

        return $pipeline ? (int) $pipeline->id : null;
    }

    /**
     * Comparación tolerante a acentos, mayúsculas y espacios de más: las dos
     * tablas las cargaron personas distintas en momentos distintos, y "Palpalá"
     * contra "palpala" no puede ser una ciudad diferente.
     */
    protected function normalizar(string $valor): string
    {
        // Delega en Core: el puente grupo→pipeline usa la MISMA comparación, y si
        // las dos se separan el pedido se abre en una ciudad y se cobra en otra.
        return AlcanceCiudades::normalizar($valor);
    }

    /**
     * Los productos que esa sucursal vende, con su precio.
     *
     * @return array<int, array{id:int, name:string, sku:?string, price:float}>
     */
    public function productosDe(int $sucursalId): array
    {
        $atributo = $this->attributes->findOneByField('code', self::ATRIBUTO);

        // Sin el atributo configurado no hay forma de saber qué vende cada
        // sucursal. Se devuelve vacío en vez del catálogo completo: mostrar todo
        // sería mostrar precios de otras ciudades.
        if (! $atributo) {
            return [];
        }

        $todas = (int) config('distribuidor.sucursal_todas');

        $permitidos = DB::table('attribute_values')
            ->where('attribute_id', $atributo->id)
            ->whereNotNull('text_value')
            ->get(['entity_id', 'text_value'])
            ->filter(function ($fila) use ($sucursalId, $todas) {
                $ids = array_map('intval', array_filter(explode(',', (string) $fila->text_value), 'strlen'));

                return in_array($sucursalId, $ids, true) || in_array($todas, $ids, true);
            })
            ->pluck('entity_id')
            ->unique()
            ->all();

        if (! $permitidos) {
            return [];
        }

        // Solo activos: un producto dado de baja no se ofrece en el Punto.
        return \Webkul\Product\Support\ProductosActivos::aplicar(DB::table('products'), 'products.id')
            ->whereIn('id', $permitidos)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'price'])
            ->map(fn ($p) => [
                'id'    => (int) $p->id,
                'name'  => (string) $p->name,
                'sku'   => $p->sku,
                'price' => (float) $p->price,
            ])
            ->all();
    }
}
