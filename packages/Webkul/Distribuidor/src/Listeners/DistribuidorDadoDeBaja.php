<?php

namespace Webkul\Distribuidor\Listeners;

use Illuminate\Support\Facades\Log;
use Webkul\Core\Services\Distribuidores;
use Webkul\Distribuidor\Services\Reparto;

/**
 * Se dio de baja a un distribuidor: sus clientes se reparten entre los demás.
 *
 * Un cliente a nombre de alguien que ya no entra al CRM no lo ve nadie: ni el que se
 * fue ni los demás distribuidores, que sólo ven lo suyo. Cada cliente se reparte por
 * el turno de su ciudad, con sus pedidos abiertos y su conversación.
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

        $resultado = $this->reparto->repartirClientesDe((int) $user->id);

        Log::info('Clientes repartidos por baja de distribuidor', ['user_id' => $user->id] + $resultado);
    }
}
