<?php

namespace Webkul\Product\Repositories;

use Illuminate\Support\Facades\DB;
use Webkul\Core\Eloquent\Repository;

class ProductVarianteRepository extends Repository
{
    public function model(): string
    {
        return 'Webkul\Product\Contracts\ProductVariante';
    }

    /**
     * Deja las variantes del producto como vinieron del formulario.
     *
     * Borrar y reinsertar, como la lista de precios: se edita entera en una
     * pantalla y nada apunta a una variante por id - los ítems de cotización
     * guardan una FOTO, no una referencia.
     */
    public function reemplazar(int $productId, array $filas): void
    {
        DB::transaction(function () use ($productId, $filas) {
            /*
            | Se actualiza en su lugar, NO se borra y reinserta.
            |
            | Borrar y reinsertar cambia el id de cada variante en cada guardado,
            | y hay cosas que apuntan a ese id: los adjuntos por variante (la
            | ficha de 50 L). Pasó el 21/09: se asignaron las cinco fichas de
            | Magia Verde, alguien guardó el producto un minuto después y las
            | cinco quedaron sin variante. Al cliente que pidió 50 L le llegaron
            | tres PDFs.
            |
            | La identidad de una variante son sus valores de eje («Tamaño: 50 L»):
            | si la fila del formulario tiene los mismos valores que una existente,
            | es la misma variante y conserva su id. Lo que ya no viene se borra;
            | lo nuevo se crea.
            */
            $existentes = $this->getModel()->newQuery()
                ->where('product_id', $productId)
                ->get()
                ->keyBy(fn ($v) => self::claveDe((array) $v->valores));

            $vistas = [];

            foreach ($filas as $fila) {
                $clave = self::claveDe((array) ($fila['valores'] ?? []));

                if ($existente = $existentes->get($clave)) {
                    $existente->fill($fila)->save();
                } else {
                    $this->create($fila + ['product_id' => $productId]);
                }

                $vistas[$clave] = true;
            }

            foreach ($existentes as $clave => $variante) {
                if (! isset($vistas[$clave])) {
                    $variante->delete();
                }
            }
        });
    }

    /**
     * La identidad de una variante como texto comparable: los valores de eje
     * ordenados por nombre de eje, así el orden de las claves del JSON -que
     * MySQL normaliza- no la cambia.
     */
    protected static function claveDe(array $valores): string
    {
        ksort($valores);

        return json_encode(array_map(fn ($v) => trim((string) $v), $valores), JSON_UNESCAPED_UNICODE);
    }
}
