<?php

namespace Webkul\Quote\Repositories;

use Illuminate\Container\Container;
use Webkul\Core\Eloquent\Repository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Support\Variantes;

class QuoteItemRepository extends Repository
{
    /**
     * Create a new repository instance.
     *
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        Container $container
    ) {
        parent::__construct($container);
    }

    /**
     * Specify Model class name
     *
     * @return mixed
     */
    public function model()
    {
        return 'Webkul\Quote\Contracts\QuoteItem';
    }

    /**
     * @return mixed
     */
    public function create(array $data)
    {
        if (empty($data['product_id'])) {
            return null;
        }

        $product = $this->productRepository->find($data['product_id']);

        // Sin producto no hay de dónde sacar el sku ni el nombre. Antes esto
        // reventaba con un findOrFail que Laravel renderiza como 404.
        if (! $product && empty($data['sku'])) {
            return null;
        }

        $foto = $this->fotoDeLaVariante($product, $data);

        // De entrada, no columnas: dicen qué variante buscar.
        unset($data['criterios'], $data['variante_id']);

        return parent::create(array_merge(
            $data,
            $this->fotoDelProducto($product),
            $foto,
            $this->descripcion($data, $foto, $product),
        ));
    }

    /**
     * @param  int  $id
     * @param  string  $attribute
     * @return \Webkul\Quote\Contracts\QuoteItem
     */
    public function update(array $data, $id, $attribute = 'id')
    {
        $product = $this->productRepository->find($data['product_id'] ?? null);

        /*
        | El ítem NO se pierde si el producto ya no está en el catálogo.
        |
        | `quote_items` guarda `sku` y `name` COPIADOS del producto: es una foto
        | de lo que se cotizó, no un puntero. Existe justamente para que la
        | cotización sobreviva a que el catálogo cambie.
        |
        | El `findOrFail` que había acá contradecía ese diseño: borrar un
        | producto dejaba inservible para siempre la edición de toda cotización
        | que lo hubiera incluido, con un 404 sobre una página que sí existe. Y
        | `quote_items.product_id` no tiene FK, así que los huérfanos se generan
        | en silencio.
        |
        | Cuando el producto falta se conservan el sku y el nombre que el ítem ya
        | tiene, que son los que se le cotizaron al cliente.
        */
        $foto = $this->fotoDeLaVariante($product, $data);

        unset($data['criterios'], $data['variante_id']);

        return parent::update(array_merge(
            $data,
            $this->fotoDelProducto($product),
            $foto,
            $this->descripcion($data, $foto, $product),
        ), $id);
    }

    /**
     * Los códigos que el ítem copia de la variante: TR4, barra, medida, unidad.
     *
     * Se resuelve desde `criterios` —lo que el cliente eligió— igual que el
     * precio. Es una FOTO: corregir un TR4 mañana no puede cambiar una
     * cotización que el cliente ya tiene en la mano.
     *
     * Lo que venga escrito a mano en el formulario GANA sobre la variante: el
     * asesor completa el código de barra que todavía no está cargado, o corrige
     * uno puntual para un cliente. Por eso se descartan las claves que ya vienen
     * con valor en `$data`.
     *
     * Sin producto, sin criterios o sin variante no se toca nada: `array_merge`
     * con un array vacío deja lo que ya tenía la fila.
     */
    protected function fotoDeLaVariante($product, array $data): array
    {
        if (! $product) {
            return [];
        }

        $product->loadMissing('variantes');

        /*
        | Dos caminos hacia la misma variante:
        |
        |   `variante_id` lo manda el FORMULARIO — el asesor la eligió del
        |   select, así que no hay nada que resolver.
        |
        |   `criterios` los manda el AGENTE — dice qué eligió el cliente
        |   ({Tamaño: 50 L, Canal: HORECA}) y el CRM busca la variante.
        */
        $variante = isset($data['variante_id']) && $data['variante_id']
            ? $product->variantes->firstWhere('id', (int) $data['variante_id'])
            : null;

        if (! $variante) {
            $criterios = (array) ($data['criterios'] ?? $data['variante'] ?? []);

            if (! $criterios) {
                return [];
            }

            $variante = Variantes::resolver($product, $criterios);
        }

        if (! $variante) {
            return [];
        }

        // Lo escrito a mano manda: solo se completan las claves que no vinieron.
        return array_diff_key(
            $variante->foto(),
            array_filter($data, fn ($v) => $v !== null && $v !== '')
        );
    }

    /**
     * Los datos que el ítem copia del producto, si el producto todavía existe.
     *
     * Vacío cuando no: `array_merge` deja intacto lo que ya tiene la fila en vez
     * de pisarlo con nulls.
     */
    protected function fotoDelProducto($product): array
    {
        return $product
            ? ['sku' => $product->sku, 'name' => $product->name]
            : [];
    }

    /**
     * Descripcion final de la linea, en orden de prioridad.
     *
     *   1. Lo que el asesor escribio en el formulario.
     *   2. El nombre del ERP de la variante elegida.
     *   3. El nombre del producto.
     *
     * El PDF imprime esto en la columna ITEM. Sin el paso 2 todas las lineas de
     * una cotizacion de bolsas dirian «Bolsas Magia Verde» y el cliente no
     * sabria cual es cual.
     */
    protected function descripcion(array $data, array $variante, $product): array
    {
        $delFormulario = trim((string) ($data['name'] ?? ''));

        if ($delFormulario !== '') {
            return ['name' => $delFormulario];
        }

        return array_filter([
            'name' => $variante['name'] ?? ($product?->name),
        ], fn ($v) => $v !== null);
    }
}
