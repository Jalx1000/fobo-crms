<?php

namespace Webkul\Inmobiliaria\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Webkul\Core\Eloquent\Repository;

class InmuebleMediaRepository extends Repository
{
    protected $fieldSearchable = ['inmueble_id', 'tipo'];

    public function model(): string
    {
        return 'Webkul\Inmobiliaria\Contracts\InmuebleMedia';
    }

    /**
     * Qué archivos mandarle al cliente por WhatsApp.
     *
     * El asesor puede cargar cuantas imágenes quiera; el envío está acotado
     * (`media_cantidad_max`) para no inundar el chat. La portada va primero,
     * después el orden que definió el asesor.
     */
    public function paraEnviar(int $inmuebleId, string $tipo, ?int $cantidad = null): Collection
    {
        $cfg = config('inmobiliaria.inmuebles');

        $cantidad = min(
            (int) ($cantidad ?: $cfg['media_cantidad_default']),
            (int) $cfg['media_cantidad_max']
        );

        return $this->model->newQuery()
            ->where('inmueble_id', $inmuebleId)
            ->where('tipo', $tipo)
            ->orderByDesc('es_portada')
            ->orderBy('orden')
            ->limit($cantidad)
            ->get();
    }
}
