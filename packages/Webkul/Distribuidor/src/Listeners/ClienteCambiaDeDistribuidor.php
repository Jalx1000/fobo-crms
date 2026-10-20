<?php

namespace Webkul\Distribuidor\Listeners;

use Webkul\Core\Services\Distribuidores;
use Webkul\Distribuidor\Services\Reparto;

/**
 * Un encargado le cambió el dueño al cliente desde Contactos.
 *
 * El cliente no puede quedar repartido entre dos empresas: si pasa a otro
 * distribuidor, sus pedidos abiertos y su conversación de WhatsApp pasan con él.
 * Los cerrados se quedan con quien los entregó.
 *
 * Si el dueño nuevo no es distribuidor (un encargado se lo dejó a sí mismo, por
 * ejemplo), no se mueve nada: esos roles no reparten, y mover los pedidos a su
 * nombre los sacaría de la lista de quien tiene que entregarlos.
 */
class ClienteCambiaDeDistribuidor
{
    public function __construct(
        protected Distribuidores $distribuidores,
        protected Reparto $reparto,
    ) {}

    public function handle($person): void
    {
        $userId = $person?->user_id ? (int) $person->user_id : null;

        if (! $userId || ! $this->distribuidores->esDistribuidor($userId)) {
            return;
        }

        $this->reparto->asignarCliente((int) $person->id, $userId);
    }
}
