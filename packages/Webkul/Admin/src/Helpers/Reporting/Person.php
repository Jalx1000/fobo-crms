<?php

namespace Webkul\Admin\Helpers\Reporting;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Repositories\PersonRepository;

class Person extends AbstractReporting
{
    /**
     * Create a helper instance.
     *
     * @return void
     */
    public function __construct(protected PersonRepository $personRepository)
    {
        parent::__construct();
    }

    /**
     * Retrieves total persons and their progress.
     */
    public function getTotalPersonsProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalPersons($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getTotalPersons($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total persons by date
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getTotalPersons($startDate, $endDate): int
    {
        $pipelineId = is_numeric(request('pipeline_id')) ? (int) request('pipeline_id') : null;

        // Este KPI cuenta CONTACTOS (personas registradas por persons.created_at) y debe
        // cuadrar 1:1 con el módulo de Prospectos. Por eso la ciudad se filtra igual que
        // la lista: por el atributo del contacto `cliente_ciudad` (NO por el pipeline del
        // lead) y sin JOIN a leads, para no perder a las personas que aún no tienen ningún
        // lead. "Sin ciudad" incluye además a las personas sin ese atributo (integer_value
        // = pipeline "Sin ciudad" o NULL).
        $query = $this->personRepository
            ->resetModel()
            ->whereBetween('persons.created_at', [$startDate, $endDate]);

        // Asesor: solo los contactos que tienen algún lead suyo. Con EXISTS y no
        // con JOIN, para no contar dos veces a quien tiene varios leads.
        if (is_numeric($userId = request('user_id'))) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('leads')
                ->whereColumn('leads.person_id', 'persons.id')
                ->where('leads.user_id', $userId));
        }

        if ($pipelineId && ($cityAttributeId = $this->getCityAttributeId())) {
            $query->leftJoin('attribute_values as city_av', function ($join) use ($cityAttributeId) {
                $join->on('city_av.entity_id', '=', 'persons.id')
                    ->where('city_av.entity_type', 'persons')
                    ->where('city_av.attribute_id', $cityAttributeId);
            });

            if ($pipelineId === $this->getNoCityPipelineId()) {
                $query->where(function ($q) use ($pipelineId) {
                    $q->where('city_av.integer_value', $pipelineId)
                        ->orWhereNull('city_av.integer_value');
                });
            } else {
                $query->where('city_av.integer_value', $pipelineId);
            }
        }

        return $query->distinct('persons.id')->count('persons.id');
    }

    /**
     * Id del atributo custom "cliente_ciudad" de personas (la ciudad del contacto).
     * Se resuelve por code, no hardcodeado, para que siga funcionando entre entornos.
     * Mismo criterio que Webkul\Admin\DataGrids\Contact\PersonDataGrid.
     */
    protected function getCityAttributeId(): ?int
    {
        static $attributeId;

        if ($attributeId === null) {
            $attributeId = DB::table('attributes')
                ->where('code', 'cliente_ciudad')
                ->where('entity_type', 'persons')
                ->value('id') ?? false;
        }

        return $attributeId ?: null;
    }

    /**
     * Id del pipeline "Sin ciudad", usado como bucket de los prospectos que no tienen
     * valor de `cliente_ciudad`. Se resuelve por nombre para no depender de un id fijo.
     */
    protected function getNoCityPipelineId(): ?int
    {
        static $pipelineId;

        if ($pipelineId === null) {
            $pipelineId = DB::table('lead_pipelines')
                ->where('name', 'Sin ciudad')
                ->value('id') ?? false;
        }

        return $pipelineId ?: null;
    }

    /**
     * Gets top customers by revenue.
     *
     * @param  int  $limit
     */
    public function getTopCustomersByRevenue($limit = null): Collection
    {
        $tablePrefix = DB::getTablePrefix();

        $items = $this->personRepository
            ->resetModel()
            // Solo la condición: el JOIN a leads ya está más abajo, y repetirlo
            // da «Not unique table/alias» en cuanto llega un user_id.
            ->when(request('user_id'), fn ($q) => $q->where('leads.user_id', request('user_id')))
            ->when(request('organization_id'), function ($q) {
                $q->where('persons.organization_id', request('organization_id'));
            })
            ->leftJoin('leads', 'persons.id', '=', 'leads.person_id')
            ->select('*', 'persons.id as id')
            ->addSelect(DB::raw('SUM('.$tablePrefix.'leads.lead_value) as revenue'))
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->having(DB::raw('SUM('.$tablePrefix.'leads.lead_value)'), '>', 0)
            ->groupBy('person_id')
            ->orderBy('revenue', 'DESC')
            ->limit($limit)
            ->get();

        $items = $items->map(function ($item) {
            return [
                'id'                => $item->id,
                'name'              => $item->name,
                'emails'            => $item->emails,
                'contact_numbers'   => $item->contact_numbers,
                'revenue'           => $item->revenue,
                'formatted_revenue' => core()->formatBasePrice($item->revenue),
            ];
        });

        return $items;
    }
}
