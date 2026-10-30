<?php

namespace Webkul\Admin\Services\Smd;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Webkul\Admin\Services\ShareMeDataService;

/**
 * La agenda de SMD de TODOS los doctores, pedida una sola vez por rango de días
 * y compartida por quien la necesite (WhatsApp, calendario, agente IA).
 *
 * En SMD todos los doctores están en una sola especialidad ("General"), así que
 * una consulta trae a todos; qué doctor atiende qué especialidad lo decide Sofo
 * (doctor_specialty). Antes cada consumidor consultaba una vez por especialidad
 * (planning/16-especialidades-en-crm).
 *
 * SMD publica bloques libres de 15 min: un horario está libre solo si lo están
 * TODOS sus bloques (D-7).
 */
class SmdAgenda
{
    /** Cuánto se reusa una agenda ya pedida. */
    public const TTL_MINUTES = 5;

    protected const BLOCK_MINUTES = 15;

    /** HTTP status de la última consulta real a SMD (null = sin respuesta). */
    public ?int $lastStatus = null;

    public function __construct(
        protected ShareMeDataService $smd,
    ) {}

    /** Modo nuevo: una consulta con la especialidad única. */
    public static function enabled(): bool
    {
        return config('smd.availability_mode') === 'single';
    }

    /**
     * Bloques libres de cada médico de SMD, indexados por su `_id`, en el
     * formato crudo de `slots` (lo entiende AvailabilityResolverService::parseSmdSlots).
     *
     * @return array<string, array>|null null = SMD no respondió (no se guarda)
     */
    public function all(Carbon $start, int $days, ?int $timeout = null): ?array
    {
        $key = $this->key($start, $days);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $from = $start->copy()->startOfDay();
        $items = $this->smd->agenda(
            $from->format('Y-m-d H:i:s'),
            $from->copy()->addDays($days - 1)->endOfDay()->format('Y-m-d H:i:s'),
            $timeout,
        );
        $this->lastStatus = $this->smd->getLastResponse()['status'] ?? null;

        if ($items === null) {
            return null;
        }

        $agenda = $this->byPhysician($items);
        Cache::put($key, $agenda, now()->addMinutes(self::TTL_MINUTES));

        return $agenda;
    }

    /** La agenda ya guardada de ese rango, sin consultar SMD. */
    public function cached(Carbon $start, int $days): ?array
    {
        $cached = Cache::get($this->key($start, $days));

        return is_array($cached) ? $cached : null;
    }

    /**
     * Bloques libres de un médico. [] si SMD no lo publica o no tiene horarios.
     *
     * @return array|null null = SMD no respondió
     */
    public function forDoctor(string $uniqueId, Carbon $start, int $days): ?array
    {
        $agenda = $this->all($start, $days);

        return $agenda === null ? null : ($agenda[$uniqueId] ?? []);
    }

    /**
     * ¿Está libre el médico en ese horario? Consulta SMD en el momento, sin
     * agenda guardada: es lo que se usa para confirmar una cita (D-10).
     *
     * @return bool|null null = SMD no respondió
     */
    public function isAvailable(string $uniqueId, Carbon $from, Carbon $to): ?bool
    {
        $items = $this->smd->agenda($from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'));

        if ($items === null) {
            return null;
        }

        return self::covers($this->byPhysician($items)[$uniqueId] ?? [], $from, $to);
    }

    /**
     * Los bloques de 15 min de [$from, $to) están todos entre los libres.
     *
     * @param  array  $slots  formato crudo de SMD
     */
    public static function covers(array $slots, Carbon $from, Carbon $to): bool
    {
        if (! $from->lt($to)) {
            return false;
        }

        $free = [];

        foreach ($slots as $daySlots) {
            foreach ((array) $daySlots as $intervals) {
                foreach ((array) $intervals as $iv) {
                    if (! empty($iv['start']) && ! empty($iv['end'])) {
                        $free[Carbon::parse($iv['start'])->timestamp.'-'.Carbon::parse($iv['end'])->timestamp] = true;
                    }
                }
            }
        }

        for ($cursor = $from->copy(); $cursor->lt($to); $cursor->addMinutes(self::BLOCK_MINUTES)) {
            $end = $cursor->copy()->addMinutes(self::BLOCK_MINUTES);

            if (! isset($free[$cursor->timestamp.'-'.$end->timestamp])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{physician?: array, slots?: array}>  $items
     * @return array<string, array>
     */
    protected function byPhysician(array $items): array
    {
        $agenda = [];

        foreach ($items as $item) {
            if ($id = (string) ($item['physician']['_id'] ?? '')) {
                $agenda[$id] = (array) ($item['slots'] ?? []);
            }
        }

        return $agenda;
    }

    protected function key(Carbon $start, int $days): string
    {
        return 'smd:agenda:'.$start->toDateString().':'.$days;
    }
}
