<?php

namespace Webkul\Lead\Repositories;

use Webkul\Core\Eloquent\Repository;

class SalesGoalRepository extends Repository
{
    public function model(): string
    {
        return 'Webkul\Lead\Contracts\SalesGoal';
    }

    /**
     * Las metas de un período, con su dueño ya cargado.
     */
    public function delPeriodo(string $periodo)
    {
        return $this->model
            ->with('user')
            ->where('periodo', $periodo)
            ->orderByRaw('user_id IS NULL DESC') // la del equipo primero
            ->orderBy('metrica')
            ->get();
    }
}
