<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Services\CitySyncService;

/**
 * Backfill de la ciudad en sus tres representaciones.
 *
 * De aquí en adelante CitySyncService mantiene todo sincronizado en cada guardado,
 * pero los registros creados ANTES de eso pueden estar desalineados (leads sin el
 * atributo `Ciudad`, personas sin `cliente_ciudad`, o valores que no coinciden con
 * el pipeline). Este comando los alinea de una sola pasada.
 *
 * Fuente de verdad: `leads.lead_pipeline_id`. Es la columna real que usan kanban,
 * tablero y reportes, así que el backfill nunca mueve un lead de pipeline: solo
 * corrige los atributos custom para que reflejen dónde está el lead realmente.
 *
 * La ciudad de una persona se toma de su lead MÁS RECIENTE (la última ciudad en la
 * que compró manda). Las personas sin ningún lead no se tocan.
 *
 * Uso:
 *   php artisan leads:sync-ciudades           # dry-run, solo reporta
 *   php artisan leads:sync-ciudades --apply   # ejecuta los cambios
 */
class SyncLeadCities extends Command
{
    protected $signature = 'leads:sync-ciudades
        {--apply : Ejecuta los cambios (sin esta bandera solo hace dry-run)}';

    protected $description = 'Alinea el atributo Ciudad (leads) y cliente_ciudad (personas) con leads.lead_pipeline_id';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $leadAttributeId = $this->attributeId('leads', CitySyncService::LEAD_CITY_ATTRIBUTE_CODE);
        $personAttributeId = $this->attributeId('persons', CitySyncService::PERSON_CITY_ATTRIBUTE_CODE);

        if (! $leadAttributeId || ! $personAttributeId) {
            $this->error('No se encontraron los atributos de ciudad. Esperados: '
                .CitySyncService::LEAD_CITY_ATTRIBUTE_CODE.' (leads) y '
                .CitySyncService::PERSON_CITY_ATTRIBUTE_CODE.' (persons).');

            return self::FAILURE;
        }

        $leadFixes = $this->collectLeadFixes($leadAttributeId);
        $personFixes = $this->collectPersonFixes($personAttributeId);

        $this->info('Leads con atributo Ciudad desalineado o faltante: '.count($leadFixes));
        $this->info('Personas con cliente_ciudad desalineado o faltante: '.count($personFixes));

        if (! $leadFixes && ! $personFixes) {
            $this->info('Todo ya está sincronizado. Nada que hacer.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->line('Dry-run: no se escribió nada. Repite con --apply para ejecutar.');

            $this->table(
                ['Entidad', 'Id', 'Ciudad actual', 'Ciudad correcta'],
                collect($leadFixes)
                    ->take(15)
                    ->map(fn ($fix) => ['lead', $fix['entity_id'], $fix['current'] ?? '—', $fix['city']])
                    ->concat(
                        collect($personFixes)
                            ->take(15)
                            ->map(fn ($fix) => ['persona', $fix['entity_id'], $fix['current'] ?? '—', $fix['city']])
                    )
                    ->all()
            );

            return self::SUCCESS;
        }

        DB::transaction(function () use ($leadFixes, $personFixes, $leadAttributeId, $personAttributeId) {
            $this->writeFixes('leads', $leadAttributeId, $leadFixes);
            $this->writeFixes('persons', $personAttributeId, $personFixes);
        });

        $this->info('Listo. '.(count($leadFixes) + count($personFixes)).' valores sincronizados.');

        return self::SUCCESS;
    }

    /**
     * Leads cuyo atributo `Ciudad` no coincide con su pipeline (o no existe).
     *
     * @return array<int, array{entity_id: int, city: int, current: int|null}>
     */
    private function collectLeadFixes(int $attributeId): array
    {
        return DB::table('leads')
            ->leftJoin('attribute_values as av', function ($join) use ($attributeId) {
                $join->on('av.entity_id', '=', 'leads.id')
                    ->where('av.entity_type', 'leads')
                    ->where('av.attribute_id', $attributeId);
            })
            ->whereNotNull('leads.lead_pipeline_id')
            ->where(function ($query) {
                $query->whereNull('av.integer_value')
                    ->orWhereColumn('av.integer_value', '!=', 'leads.lead_pipeline_id');
            })
            ->select('leads.id as entity_id', 'leads.lead_pipeline_id as city', 'av.integer_value as current')
            ->get()
            ->map(fn ($row) => [
                'entity_id' => (int) $row->entity_id,
                'city'      => (int) $row->city,
                'current'   => $row->current !== null ? (int) $row->current : null,
            ])
            ->all();
    }

    /**
     * Personas cuyo `cliente_ciudad` no coincide con la ciudad de su lead más reciente.
     *
     * @return array<int, array{entity_id: int, city: int, current: int|null}>
     */
    private function collectPersonFixes(int $attributeId): array
    {
        /**
         * Id del lead más reciente de cada persona. Se resuelve con un group by en vez
         * de una subconsulta correlacionada cruda para que el prefijo de tablas
         * (DB_PREFIX) lo siga aplicando el query builder.
         */
        $latestLeadIds = DB::table('leads')
            ->whereNotNull('person_id')
            ->whereNotNull('lead_pipeline_id')
            ->groupBy('person_id')
            ->select('person_id', DB::raw('MAX(id) as lead_id'));

        return DB::table('leads as latest')
            ->joinSub($latestLeadIds, 'picked', 'picked.lead_id', '=', 'latest.id')
            ->leftJoin('attribute_values as av', function ($join) use ($attributeId) {
                $join->on('av.entity_id', '=', 'latest.person_id')
                    ->where('av.entity_type', 'persons')
                    ->where('av.attribute_id', $attributeId);
            })
            ->where(function ($query) {
                $query->whereNull('av.integer_value')
                    ->orWhereColumn('av.integer_value', '!=', 'latest.lead_pipeline_id');
            })
            ->select(
                'latest.person_id as entity_id',
                'latest.lead_pipeline_id as city',
                'av.integer_value as current'
            )
            ->get()
            ->map(fn ($row) => [
                'entity_id' => (int) $row->entity_id,
                'city'      => (int) $row->city,
                'current'   => $row->current !== null ? (int) $row->current : null,
            ])
            ->all();
    }

    /**
     * Escribe los valores corregidos: update si la fila existe, insert si falta.
     *
     * @param  array<int, array{entity_id: int, city: int, current: int|null}>  $fixes
     */
    private function writeFixes(string $entityType, int $attributeId, array $fixes): void
    {
        foreach ($fixes as $fix) {
            /**
             * updateOrInsert cubre los dos casos de golpe: la fila puede no existir,
             * o existir con integer_value NULL (que en el dry-run se reporta igual
             * que "faltante" y con un insert a secas quedaría duplicada).
             */
            DB::table('attribute_values')->updateOrInsert(
                [
                    'entity_type'  => $entityType,
                    'entity_id'    => $fix['entity_id'],
                    'attribute_id' => $attributeId,
                ],
                ['integer_value' => $fix['city']]
            );
        }
    }

    private function attributeId(string $entityType, string $code): ?int
    {
        $id = DB::table('attributes')
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->value('id');

        return $id ? (int) $id : null;
    }
}
