<?php

namespace Webkul\Doctor\Repositories;

use Illuminate\Container\Container;
use Webkul\Core\Eloquent\Repository;
use Webkul\Doctor\Contracts\Shift as ShiftContract;

class ShiftRepository extends Repository
{
    protected $fieldSearchable = [
        'doctor_id',
        'date',
    ];

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    public function model()
    {
        return ShiftContract::class;
    }

    /**
     * Fecha del último turno cargado de cada doctor: [doctor_id => 'Y-m-d'].
     */
    public function lastDateByDoctor(): array
    {
        return $this->getModel()->newQuery()
            ->selectRaw('doctor_id, MAX(date) as last_date')
            ->groupBy('doctor_id')
            ->pluck('last_date', 'doctor_id')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();
    }

    /**
     * Borra todos los turnos del doctor (pasados y futuros) y devuelve cuántos.
     */
    public function deleteAllForDoctor(int $doctorId): int
    {
        return $this->getModel()->newQuery()
            ->where('doctor_id', $doctorId)
            ->delete();
    }

    /**
     * Borra los turnos del doctor entre dos fechas (incluidas) que caen en los
     * días de la semana dados (0 = domingo, como Carbon). Sin días = todos.
     * El día de la semana se filtra en PHP para no depender del motor de BD.
     */
    public function deleteForDoctorInRange(int $doctorId, string $from, string $to, ?array $days = null): int
    {
        $shifts = $this->getModel()->newQuery()
            ->where('doctor_id', $doctorId)
            ->whereBetween('date', [$from, $to])
            ->get(['id', 'date']);

        if ($days) {
            $shifts = $shifts->filter(fn ($shift) => in_array($shift->date->dayOfWeek, $days, false));
        }

        if ($shifts->isEmpty()) {
            return 0;
        }

        return $this->getModel()->newQuery()
            ->whereIn('id', $shifts->pluck('id'))
            ->delete();
    }

    /**
     * Determina si existe un turno solapado para el doctor en la misma fecha y rango horario.
     * Excluye el ID dado (útil en updates).
     */
    public function hasConflict(int $doctorId, string $date, string $startTime, string $endTime, ?int $excludeId = null): bool
    {
        $query = $this->getModel()->newQuery()
            ->where('doctor_id', $doctorId)
            ->where('date', $date)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}
