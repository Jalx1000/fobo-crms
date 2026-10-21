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
 * Si el dueño nuevo es un encargado (Huascar se queda un cliente sin ciudad, por
 * ejemplo), también se mueve, pero sólo cuando el dueño CAMBIÓ en esta edición:
 * hay clientes viejos a nombre de un administrador o encargado (cargados por el
 * agente), y editarles el teléfono no debe sacarle los pedidos al distribuidor
 * que los está entregando.
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

        if (! $userId) {
            return;
        }

        if (! $this->distribuidores->esDistribuidor($userId) && ! $person->wasChanged('user_id')) {
            return;
        }

        $this->reparto->asignarCliente((int) $person->id, $userId);
    }
}
