<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductPriceRepository;
use Webkul\Product\Repositories\ProductVarianteRepository;

/**
 * Las cuatro líneas de venta directa de Imprimir.
 *
 * Los datos salen del documento «Estructura de Agente IA - IMPRIMIR»: los cinco
 * tamaños de Magia Verde, los canales de precio, las dos presentaciones de
 * stretch film, los colores de hules y las dos variantes de tapas.
 *
 * Es IDEMPOTENTE: identifica cada producto por SKU y reescribe su lista de
 * precios entera. Se puede correr de nuevo después de cargar los precios que
 * faltan sin duplicar nada.
 *
 *     php artisan db:seed --class=Database\\Seeders\\ProductosImprimirSeeder
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DE DÓNDE SALEN LOS PRECIOS
 *
 * De la sección «4. TABLAS DE PRECIOS CON FACTURA» del documento, que estaba
 * como IMÁGENES y por eso no aparecía al leerlo como texto. Las tablas revelan
 * tres ejes que el texto no menciona:
 *
 *   Magia Verde  → Zona: Santa Cruz e Interior, que cuesta +1 Bs (§4.1 y §4.2)
 *   Hules        → Factura: con factura (CF) y sin factura (SF)      (§4.5)
 *   Stretch Film → Volumen: 1-10 / 11-30 / 31-50 / 51+               (§4.6)
 *
 * Los dos primeros ya los pregunta el protocolo sin saberlo: la ciudad del
 * PASO 2 define la zona, y el NIT del PASO 4 define si lleva factura.
 *
 * ⚠️ El precio de Magia Verde es POR PACK (10 unidades, o 5 en la de 200 L), no
 * por bolsa. Va en `unidad` de cada fila para que el agente no cotice diez veces
 * más barato.
 */
class ProductosImprimirSeeder extends Seeder
{
    protected const TAMANOS = [
        '35 L'  => '60 x 63 cm - bolsa de desechos multiuso',
        '50 L'  => '65 x 80 cm - bolsa de desechos multiuso',
        '75 L'  => '78 x 95 cm - bolsa de desechos multiuso',
        '140 L' => '90 x 110 cm - bolsa de desechos multiuso',
        '200 L' => '100 x 120 cm - bolsa XXL extragrande multiuso',
    ];

    /**
     * Mínimo para vender directo, del §5.1 del documento.
     *
     * «Si es ama de casa o quiere menos de 10 unidades» → derivar al
     * supermercado. O sea que 10 es el piso de la venta directa, y por debajo el
     * cliente no es un cliente nuestro sino de la góndola.
     *
     * La nota de la reunión del 11/09 lo precisa: «Magia Verde mínimo 10
     * paquetes». Son 10 PACKS (100 bolsas), no 10 bolsas.
     */
    protected const MINIMO_BOLSAS = '10 packs';

    /**
     * Códigos por tamaño, de la propuesta comercial real a Grupo Quiroz
     * (RG-VCO-005). El TR4 es el código del ERP de Imprimir; el de barra, el EAN.
     */
    protected const VARIANTES_BOLSAS = [
        '35 L'  => ['tr4' => '020117-0044', 'barra' => '7779970880051', 'medida' => '(60×63) cm',   'erp' => 'BSA MULTIUSO MAGIA VERDE 35L 10UN'],
        '50 L'  => ['tr4' => '020117-0035', 'barra' => '7779970880037', 'medida' => '(65×80) cm',   'erp' => 'BSA MULTIUSO MAGIA VERDE 50L 10UN'],
        '75 L'  => ['tr4' => '020117-0034', 'barra' => '7779970880020', 'medida' => '(78×95) cm',   'erp' => 'BSA MULTIUSO MAGIA VERDE 75L 10UN'],
        '140 L' => ['tr4' => '020117-0033', 'barra' => '7779970880013', 'medida' => '(90×110) cm',  'erp' => 'BSA MULTIUSO MAGIA VERDE 140L 10UN'],
        '200 L' => ['tr4' => '020117-0051', 'barra' => '7779970880068', 'medida' => '(100×120) cm', 'erp' => 'BSA MULTIUSO MAGIA VERDE 200L 5UN'],
    ];

    /** Cuántas unidades trae el pack de cada tamaño (§4.1). */
    protected const PACK = ['35 L' => 10, '50 L' => 10, '75 L' => 10, '140 L' => 10, '200 L' => 5];

    /**
     * Precio por pack, por zona y canal, en el orden de TAMANOS.
     *
     * Interior es exactamente Santa Cruz + 1 Bs en las quince combinaciones; se
     * escriben igual los dos y no se calcula uno del otro, porque la regla del
     * +1 es de hoy y el día que cambie una sola celda un cálculo la escondería.
     */
    protected const PRECIOS_BOLSAS = [
        'Santa Cruz' => [
            'TRADICIONAL' => [6.00, 10.50, 11.50, 12.00, 12.00],
            'HORECA'      => [7.50, 13.00, 15.00, 21.00, 13.00],
            'EMPRESARIAL' => [7.50, 13.00, 15.00, 21.00, 14.50],
        ],
        'Interior' => [
            'TRADICIONAL' => [7.00, 11.50, 12.50, 13.00, 13.00],
            'HORECA'      => [8.50, 14.00, 16.00, 22.00, 14.00],
            'EMPRESARIAL' => [8.50, 14.00, 16.00, 22.00, 15.50],
        ],
    ];

    /** Canales que existen pero no se cotizan por chat, con qué hacer en cada uno. */
    protected const CANALES_SIN_PRECIO = [
        /*
        | HOGAR no es «no se cotiza»: es «depende de cuánto lleve».
        |
        | El §5.1 del documento lo dice así - «si es ama de casa o quiere menos
        | de 10 unidades» va al supermercado-, o sea que por encima del mínimo es
        | venta directa como cualquier otra. La nota lleva la regla completa para
        | que el agente no tenga que deducirla: antes decía «no se cotiza por
        | chat» y el agente igual cotizaba, con una regla que se inventaba él.
        |
        | Confirmado por el negocio el 14/09.
        */
        'HOGAR'        => 'Menos del mínimo (10 packs): venta por supermercado, indicar los puntos de venta de la ciudad del cliente. Desde 10 packs es venta directa: cotizar con el precio TRADICIONAL del mismo tamaño y zona.',
        'DISTRIBUIDOR' => 'Hay precio especial para distribuidores, pero no se da por chat: pedir nombre, empresa y WhatsApp, y derivar al equipo comercial.',
    ];

    public function run(): void
    {
        $this->bolsas();
        $this->hules();
        $this->stretchFilm();
        $this->tapas();
    }

    /**
     * Bolsas Magia Verde - el precio cruza tamaño, canal y zona.
     *
     * Los canales que no se cotizan (HOGAR y DISTRIBUIDOR) se cargan por cada
     * tamaño y zona en vez de una sola vez sin ellos. Es más filas, pero el
     * agente pregunta el tamaño y la ciudad antes que el canal tan seguido como
     * al revés: una fila sin tamaño no coincidiría con «bolsas de 50 L para mi
     * casa» y el cliente recibiría un «no tengo ese dato» en lugar de la
     * derivación que le corresponde.
     */
    protected function bolsas(): void
    {
        $producto = $this->producto(
            sku: 'CM_00002',
            name: 'Bolsas Magia Verde',
            description: implode("\n", array_map(
                fn ($medida, $tamano) => "{$tamano} ({$medida})",
                self::TAMANOS,
                array_keys(self::TAMANOS),
            )),
            ejes: ['Tamaño', 'Canal', 'Zona'],
            ejesIdentidad: ['Tamaño'],
            entrega: ['tiempo_entrega' => 'Inmediata', 'lugar_entrega' => 'Puesto almacén cliente', 'entrega_domicilio_desde' => null],
            caracteristicas: [
                'Material'          => 'Plástico reciclado',
                'Tamaños'           => implode(' · ', array_keys(self::TAMANOS)),
                'Puntos de venta'   => 'Hipermaxi, Amarket, IC Norte, Fidalga, Tía y Makro (Santa Cruz) · Hipermaxi y Fidalga (La Paz) · Hipermaxi (Cochabamba)',
                'tiempo_produccion' => 'Entrega inmediata',
            ],
        );

        $tamanos = array_keys(self::TAMANOS);

        $filas = [];

        foreach (self::PRECIOS_BOLSAS as $zona => $porCanal) {
            foreach ($porCanal as $canal => $precios) {
                foreach ($tamanos as $i => $tamano) {
                    $filas[] = [
                        'valores' => ['Tamaño' => $tamano, 'Canal' => $canal, 'Zona' => $zona],
                        'precio'  => $precios[$i],
                        'unidad'  => 'por pack de '.self::PACK[$tamano].' u',
                        'minimo'  => self::MINIMO_BOLSAS,
                    ];
                }
            }

            foreach (self::CANALES_SIN_PRECIO as $canal => $nota) {
                foreach ($tamanos as $tamano) {
                    $filas[] = [
                        'valores' => ['Tamaño' => $tamano, 'Canal' => $canal, 'Zona' => $zona],
                        'precio'  => null,
                        'nota'    => $nota,
                    ];
                }
            }
        }

        $this->precios($producto, $filas);

        $this->variantes($producto, array_map(
            fn ($tamano, $v) => ['valores' => ['Tamaño' => $tamano], 'codigo_tr4' => $v['tr4'], 'codigo_barra' => $v['barra'], 'medida' => $v['medida'], 'unidad' => 'PZA', 'nombre_erp' => $v['erp']],
            array_keys(self::VARIANTES_BOLSAS),
            self::VARIANTES_BOLSAS,
        ));
    }

    /**
     * Hules - el único de los cuatro con todos sus precios en el documento.
     *
     * El documento dice «7 colores» pero solo nombra transparente y negro; los
     * cinco restantes comparten precio y van como una fila sola hasta que el
     * negocio confirme cuáles son.
     */
    protected function hules(): void
    {
        $producto = $this->producto(
            sku: 'CM_00003',
            name: 'Hules',
            description: 'Rollos de 1 m x 100 m, 75 micrones, en 7 colores. Entrega inmediata y personalizada.',
            ejes: ['Color'],
            ejesIdentidad: ['Color'],
            entrega: ['tiempo_entrega' => 'Inmediata', 'lugar_entrega' => 'Puesto almacén cliente', 'entrega_domicilio_desde' => 5],
            caracteristicas: [
                'Medidas'           => '1 m x 100 m',
                'Micronaje'         => '75 micrones',
                'Colores'           => '7',
                'tiempo_produccion' => 'Entrega inmediata',
            ],
        );

        $this->precios($producto, [
            ['valores' => ['Color' => 'Transparente'], 'precio' => 472, 'unidad' => 'por rollo'],
            ['valores' => ['Color' => 'Negro'], 'precio' => 260, 'unidad' => 'por rollo'],
            [
                'valores' => ['Color' => 'Colores'],
                'precio'  => 460,
                'unidad'  => 'por rollo',
                'nota'    => 'Aplica a los 5 colores que no son transparente ni negro. Falta confirmar cuáles son.',
            ],
        ]);

        // Sin TR4 todavía: el negocio confirmó que hay uno por color.
        $this->variantes($producto, array_map(
            fn ($color) => ['valores' => ['Color' => $color], 'unidad' => 'PZA'],
            ['Transparente', 'Negro', 'Colores'],
        ));
    }

    /**
     * Stretch Film - dos presentaciones, sin precios en el documento.
     */
    protected function stretchFilm(): void
    {
        $producto = $this->producto(
            sku: 'CM_00001',
            name: 'Stretch Film',
            description: "Plástico elástico para envolver y asegurar la carga en los pallets.\nTenemos rollo de 4.7 kg manual y de 15.7 kg automático.\nEntrega inmediata y personalizada.",
            ejes: ['Presentación'],
            ejesIdentidad: ['Presentación'],
            entrega: ['tiempo_entrega' => 'Inmediata', 'lugar_entrega' => 'Puesto almacén cliente', 'entrega_domicilio_desde' => 50],
            caracteristicas: null, // ya tiene las suyas cargadas: no se pisan.
        );

        $this->precios($producto, [
            [
                'valores' => ['Presentación' => 'Manual 4,7 kg'],
                'precio'  => null,
                'unidad'  => 'por rollo',
                'minimo'  => '50 unidades',
                'nota'    => 'Precio todavía no cargado en el CRM: derivar a un asesor comercial. El mínimo de 50 unidades es para envío.',
            ],
            [
                'valores' => ['Presentación' => 'Automático 15,7 kg'],
                'precio'  => null,
                'unidad'  => 'por rollo',
                'minimo'  => '50 unidades',
                'nota'    => 'Precio todavía no cargado en el CRM: derivar a un asesor comercial. El mínimo de 50 unidades es para envío.',
            ],
        ]);

        $this->variantes($producto, [
            ['valores' => ['Presentación' => 'Manual 4,7 kg'], 'unidad' => 'PZA'],
            ['valores' => ['Presentación' => 'Automático 15,7 kg'], 'unidad' => 'PZA'],
        ]);
    }

    /**
     * Tapas - precios y mínimos completos en el documento.
     */
    protected function tapas(): void
    {
        $producto = $this->producto(
            sku: 'CM_00004',
            name: 'Tapas',
            description: 'Tapas plásticas en caja de 5.300 unidades. Disponibles con y sin impresión.',
            ejes: ['Impresión'],
            ejesIdentidad: ['Impresión'],
            // Recoge en planta en los dos casos. El tiempo del producto es
            // «Inmediata»; la variante con impresión lo pisa con «20 días».
            entrega: ['tiempo_entrega' => 'Inmediata', 'lugar_entrega' => 'Recoge en planta', 'entrega_domicilio_desde' => null],
            caracteristicas: [
                'Contenido por caja'   => '5.300 unidades',
                'Colores de impresión' => 'Máximo 3',
                'minimo_pedido'        => '1 caja sin impresión · 30 cajas con impresión',
            ],
        );

        $this->precios($producto, [
            [
                'valores' => ['Impresión' => 'Sin impresión'],
                'precio'  => 927.50,
                'unidad'  => 'por caja de 5.300 unidades',
                'minimo'  => '1 caja',
            ],
            [
                'valores' => ['Impresión' => 'Con impresión'],
                'precio'  => 954,
                'unidad'  => 'por caja de 5.300 unidades',
                'minimo'  => '30 cajas',
                'nota'    => 'Requiere imagen en alta calidad y admite máximo 3 colores.',
            ],
        ]);

        $this->variantes($producto, [
            ['valores' => ['Impresión' => 'Sin impresión'], 'unidad' => 'CAJA'],
            ['valores' => ['Impresión' => 'Con impresión'], 'unidad' => 'CAJA', 'tiempo_entrega' => '20 días'],
        ]);
    }

    /**
     * Busca el producto por SKU y lo deja como corresponde, o lo crea.
     *
     * `price` queda en 0 porque ninguno de los cuatro se vende a precio fijo: el
     * precio sale siempre de la lista de variantes. La API nunca le muestra ese
     * 0 al agente - devuelve `precio: null` cuando hay lista cargada.
     */
    protected function producto(
        string $sku,
        string $name,
        string $description,
        array $ejes,
        ?array $caracteristicas,
        ?array $ejesIdentidad = null,
        array $entrega = [],
    ) {
        $producto = Product::where('sku', $sku)->first();

        $datos = [
            'name'           => $name,
            'description'    => $description,
            'ejes_precio'    => $ejes,
            'ejes_identidad' => $ejesIdentidad,
        ] + $entrega;

        // null = "no toques lo que ya está cargado". Sirve para Stretch Film,
        // que tiene características técnicas cargadas a mano que el documento no
        // trae y que un seeder no tiene por qué borrar.
        if ($caracteristicas !== null) {
            $datos['caracteristicas'] = $caracteristicas;
        }

        if ($producto) {
            $producto->forceFill($datos)->save();

            $this->command?->info("· {$sku} {$name} - actualizado");

            return $producto->refresh();
        }

        /*
        | Directo al modelo y no por ProductRepository: el repositorio espera un
        | `entity_type` para guardar los atributos personalizados, y `db:seed`
        | corre dentro de Model::unguarded() - ahí `entity_type` deja de ser
        | filtrado por $fillable y se cuela al INSERT como si fuera una columna.
        |
        | No se pierde nada: los productos que ya estaban cargados a mano tampoco
        | tienen valores de atributos personalizados. Se cargan desde el CRM.
        */
        $producto = Product::create($datos + [
            'sku'      => $sku,
            'price'    => 0,
            'quantity' => 0,
        ]);

        $this->command?->info("· {$sku} {$name} - creado (id {$producto->id})");

        return $producto;
    }

    /**
     * Deja las variantes del producto. Una variante sin TR4 es válida: hoy solo
     * están los códigos de Magia Verde; el resto se completa desde el CRM.
     */
    protected function variantes($producto, array $filas): void
    {
        app(ProductVarianteRepository::class)->reemplazar(
            $producto->id,
            array_map(
                fn ($fila, $i) => $fila + ['codigo_tr4' => null, 'codigo_barra' => null, 'medida' => null, 'unidad' => 'PZA', 'nombre_erp' => null, 'tiempo_entrega' => null, 'orden' => $i],
                $filas,
                array_keys($filas),
            ),
        );

        $sinCodigo = count(array_filter($filas, fn ($f) => empty($f['codigo_tr4'])));

        $this->command?->line('  '.count($filas).' variantes'.($sinCodigo ? " ({$sinCodigo} sin TR4)" : ''));
    }

    protected function precios($producto, array $filas): void
    {
        app(ProductPriceRepository::class)->reemplazar(
            $producto->id,
            array_map(
                fn ($fila, $i) => $fila + ['unidad' => null, 'minimo' => null, 'nota' => null, 'orden' => $i],
                $filas,
                array_keys($filas),
            ),
        );

        $sinPrecio = count(array_filter($filas, fn ($f) => ($f['precio'] ?? null) === null));

        $this->command?->line('  '.count($filas).' combinaciones'.($sinPrecio ? " ({$sinPrecio} sin precio)" : ''));
    }
}
