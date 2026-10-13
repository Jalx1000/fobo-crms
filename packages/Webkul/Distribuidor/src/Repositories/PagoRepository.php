<?php

namespace Webkul\Distribuidor\Repositories;

use Webkul\Core\Eloquent\Repository;

class PagoRepository extends Repository
{
    public function model(): string
    {
        return 'Webkul\Distribuidor\Contracts\Pago';
    }

    /**
     * Lo cobrado de un pedido.
     *
     * Se suma en SQL y no en PHP: el Punto pide esto para cada pedido de la lista
     * del día, y traer todas las filas de cobro para sumarlas acá sería una
     * consulta por pedido.
     */
    public function cobrado(int $leadId): float
    {
        return (float) $this->model->where('lead_id', $leadId)->sum('amount');
    }

    /**
     * Lo cobrado de varios pedidos, en una sola consulta.
     *
     * @param  array<int, int>  $leadIds
     * @return array<int, float> lead_id => total
     */
    public function cobradoDe(array $leadIds): array
    {
        if (! $leadIds) {
            return [];
        }

        return $this->model
            ->selectRaw('lead_id, sum(amount) as total')
            ->whereIn('lead_id', $leadIds)
            ->groupBy('lead_id')
            ->pluck('total', 'lead_id')
            ->map(fn ($t) => (float) $t)
            ->all();
    }
}
