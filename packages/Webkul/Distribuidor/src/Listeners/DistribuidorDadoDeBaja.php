<?php

namespace Webkul\Distribuidor\Listeners;

use Illuminate\Support\Facades\Log;
use Webkul\Core\Services\Distribuidores;
use Webkul\Distribuidor\Services\Reparto;

/**
 * Se dio de baja a un distribuidor: sus clientes pasan al responsable (Huascar).
 *
 * Un cliente a nombre de alguien que ya no entra al CRM no lo ve nadie: ni el que se
 * fue ni los demás distribuidores, que sólo ven lo suyo. Sus fichas quedan sin dueño y
 * sus pedidos abiertos van al responsable, que decide a qué distribuidor dárselos.
 */
class DistribuidorDadoDeBaja
{
    public function __construct(
        protected Distribuidores $distribuidores,
        protected Reparto $reparto,
    ) {}

    public function handle($user): void
    {
        if (! $user || (int) $user->status === 1 || ! $this->distribuidores->esDistribuidor((int) $user->id)) {
            return;
        }

        $resultado = $this->reparto->liberarClientesDe((int) $user->id);

        Log::info('Clientes liberados por baja de distribuidor', ['user_id' => $user->id] + $resultado);
    }
}
