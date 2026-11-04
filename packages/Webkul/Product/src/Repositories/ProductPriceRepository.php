<?php

namespace Webkul\Product\Repositories;

use Illuminate\Support\Facades\DB;
use Webkul\Core\Eloquent\Repository;

class ProductPriceRepository extends Repository
{
    public function model(): string
    {
        return 'Webkul\Product\Contracts\ProductPrice';
    }

    /**
     * Deja la lista del producto exactamente como vino del formulario.
     *
     * Se borra y se vuelve a insertar en vez de comparar fila por fila. La lista
     * se edita entera -el asesor agrega, saca y reordena filas en la misma
     * pantalla- y no hay nada que apunte a un `product_price` por id: ninguna
     * cotización guarda la fila que usó, guarda el precio. Sin referencias que
     * romper, reconciliar sería complejidad sin beneficio.
     *
     * En transacción para que un error a mitad no deje al producto sin lista:
     * un producto de precio variable con cero filas es peor que uno sin editar,
     * porque el agente lo lee como "no tiene precio cargado".
     */
    public function reemplazar(int $productId, array $filas): void
    {
        DB::transaction(function () use ($productId, $filas) {
            $this->getModel()->newQuery()->where('product_id', $productId)->delete();

            foreach ($filas as $fila) {
                $this->create($fila + ['product_id' => $productId]);
            }
        });
    }
}
