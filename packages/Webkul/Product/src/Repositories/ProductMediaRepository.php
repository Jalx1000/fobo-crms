<?php

namespace Webkul\Product\Repositories;

use Illuminate\Support\Collection;
use Webkul\Core\Eloquent\Repository;

class ProductMediaRepository extends Repository
{
    public function model(): string
    {
        return 'Webkul\Product\Contracts\ProductMedia';
    }

    /**
     * El orden arranca donde terminó lo ya cargado, para que subir en tandas no
     * reordene lo que el asesor ya acomodó.
     */
    public function ultimoOrden(int $productId, string $tipo): int
    {
        return (int) $this->getModel()->newQuery()
            ->where('product_id', $productId)
            ->where('tipo', $tipo)
            ->max('orden');
    }

    /**
     * Una sola portada por producto: marcar una desmarca la anterior.
     */
    public function marcarPortada(int $productId, int $mediaId): void
    {
        $this->getModel()->newQuery()
            ->where('product_id', $productId)
            ->update(['es_portada' => false]);

        $this->getModel()->newQuery()
            ->where('product_id', $productId)
            ->where('id', $mediaId)
            ->update(['es_portada' => true]);
    }

    /**
     * Los adjuntos que se le mandan al cliente, en el orden en que los va a ver.
     *
     * La portada primero y después el orden que dejó el asesor: mandar la
     * portada tercera hace que el cliente vea un detalle antes que la foto
     * principal, y para cuando llega la buena ya dejó de mirar.
     *
     * La cantidad se topea siempre contra el máximo de config, incluso si quien
     * llama pide más: es el límite que protege el chat del cliente, no una
     * sugerencia.
     */
    /**
     * Los adjuntos que se le mandan al cliente.
     *
     * Con `$varianteId`, primero los de ESA variante (la ficha de 50 L cuando
     * pidió 50 L); si esa variante no tiene ninguno, los generales del
     * producto -los que no son de ninguna variante-, que es lo que se mandaba
     * antes. Los de OTRAS variantes nunca: mandarle al cliente la ficha de
     * 140 L cuando pidió 50 L es peor que no mandarle nada.
     *
     * Sin variante, todo lo del producto, como siempre.
     */
    public function paraEnviar(int $productId, string $tipo, ?int $cantidad = null, ?int $varianteId = null): Collection
    {
        $cfg = config('adjuntos.envio');

        $cantidad = min(
            (int) ($cantidad ?: $cfg['cantidad_default']),
            (int) $cfg['cantidad_max'],
        );

        $base = fn () => $this->getModel()->newQuery()
            ->where('product_id', $productId)
            ->where('tipo', $tipo)
            ->orderByDesc('es_portada')
            ->orderBy('orden')
            ->limit($cantidad);

        if ($varianteId) {
            $propios = $base()->where('variante_id', $varianteId)->get();

            return $propios->isNotEmpty()
                ? $propios
                : $base()->whereNull('variante_id')->get();
        }

        return $base()->get();
    }

    /**
     * Si este tipo de adjunto tiene versiones por variante en el producto.
     *
     * Cuando las tiene, «mandar todo» ya no es una opción: hay que saber cuál.
     */
    public function tieneVersionesPorVariante(int $productId, string $tipo): bool
    {
        return $this->getModel()->newQuery()
            ->where('product_id', $productId)
            ->where('tipo', $tipo)
            ->whereNotNull('variante_id')
            ->exists();
    }
}
