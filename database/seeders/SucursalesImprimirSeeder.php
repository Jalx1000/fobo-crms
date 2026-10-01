<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Product\Models\Product;
use Webkul\Warehouse\Models\Warehouse;

/**
 * Las tres sucursales de Imprimir, con los enlaces que pasó el negocio el 14/09.
 *
 * Son sucursales por LÍNEA DE PRODUCTO, no por ciudad: las tres están en Santa
 * Cruz y lo que las distingue es qué fabrica cada una. Viven en el módulo
 * Warehouse, que ya tiene su pantalla en Configuración → Almacenes.
 *
 * Idempotente: identifica por nombre y actualiza. Se puede correr de nuevo
 * cuando lleguen las direcciones escritas o los teléfonos.
 *
 *     php artisan db:seed --class=Database\\Seeders\\SucursalesImprimirSeeder
 */
class SucursalesImprimirSeeder extends Seeder
{
    protected const SUCURSALES = [
        [
            'name'     => 'Imprimir 1',
            'linea'    => 'División de Embalajes',
            'maps_url' => 'https://maps.app.goo.gl/5sCggqMUD1vZ8Evq8',
        ],
        [
            'name'     => 'Imprimir 2',
            'linea'    => 'Tapas',
            'maps_url' => 'https://maps.app.goo.gl/WCjyTGf4VAqjrQ4V7?g_st=iw',
        ],
        [
            'name'     => 'Imprimir 3',
            'linea'    => 'Envases Flexibles',
            'maps_url' => 'https://maps.app.goo.gl/7w5END7cgWJVAiYR7?g_st=iw',
        ],
    ];

    public function run(): void
    {
        foreach (self::SUCURSALES as $datos) {
            /*
            | Columnas primero y atributos personalizados después, por separado.
            |
            | `WarehouseRepository::create()` le pasa TODO el array al modelo, y
            | `db:seed` corre dentro de Model::unguarded(): `linea` y `maps_url`
            | —que son atributos, no columnas— se colarían al INSERT y MySQL
            | rechazaría la fila.
            */
            $sucursal = Warehouse::updateOrCreate(
                ['name' => $datos['name']],
                ['description' => 'Sucursal '.$datos['linea'].' — Santa Cruz'],
            );

            $this->atributos($sucursal, [
                'linea'    => $datos['linea'],
                'maps_url' => $datos['maps_url'],
            ]);

            $this->command?->info("· {$sucursal->name} — {$datos['linea']}");
        }

        $this->asignarProductos();
    }

    /**
     * Guarda solo los atributos indicados, sin tocar el resto.
     *
     * Se le pasa la lista acotada a `save()`: sin ella recorre todos los de la
     * entidad y los que no vengan en el array los reescribiría con nada.
     */
    protected function atributos(Warehouse $sucursal, array $valores): void
    {
        $repo = app(AttributeRepository::class);

        $attributes = collect(array_keys($valores))
            ->map(fn ($code) => $repo->getAttributeByCode($code, 'warehouses'))
            ->filter();

        if ($attributes->isEmpty()) {
            return;
        }

        app(AttributeValueRepository::class)->save(array_merge($valores, [
            'entity_type' => 'warehouses',
            'entity_id'   => $sucursal->id,
        ]), $attributes);
    }

    /**
     * Qué sucursal despacha cada producto.
     *
     * Solo se asigna TAPAS, que es la única sin ambigüedad: el nombre de la
     * sucursal coincide con el producto, y es el único que se retira en planta
     * —los demás se entregan en almacén del cliente o a domicilio, así que hoy
     * la sucursal no cambia lo que el agente le dice al cliente—.
     *
     * Bolsas, hules y stretch film quedan SIN sucursal hasta que el negocio
     * confirme cuál las fabrica. Se asignan desde el formulario del producto en
     * dos clics; adivinarlas pondría una dirección equivocada en un PDF.
     */
    protected function asignarProductos(): void
    {
        $tapas = Warehouse::where('name', 'Imprimir 2')->first();

        if (! $tapas) {
            return;
        }

        Product::where('sku', 'CM_00004')->whereNull('warehouse_id')->update(['warehouse_id' => $tapas->id]);

        $this->command?->line("  Tapas → {$tapas->name}");

        if ($sin = Product::whereNull('warehouse_id')->count()) {
            $this->command?->warn("  {$sin} producto(s) sin sucursal: asignar desde el CRM cuando el negocio confirme cuál los fabrica.");
        }
    }
}
