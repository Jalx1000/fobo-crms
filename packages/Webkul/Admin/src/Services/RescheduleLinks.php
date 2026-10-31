<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vínculo entre una cita reprogramada y la que la reemplaza (planning/17, D6),
 * guardado por AppointmentRescheduler en activities.additional. Lo muestran el
 * detalle de la cita y el historial del paciente; el tablero no.
 */
class RescheduleLinks
{
    /**
     * Para cada cita: la otra punta del vínculo con su fecha y hora, o null.
     * Dos consultas para todas las citas (no una por cita).
     *
     * @param  array<int, int>  $activityIds
     * @return array<int, array{rescheduled_from: ?array, rescheduled_to: ?array}>
     */
    public function forActivities(array $activityIds): array
    {
        if (! $activityIds) {
            return [];
        }

        $links = DB::table('activities')
            ->whereIn('id', $activityIds)
            ->where('additional', 'like', '%rescheduled_%')
            ->pluck('additional', 'id')
            ->map(fn ($json) => json_decode((string) $json, true) ?: []);

        $otherIds = $links->flatMap(fn ($a) => [$a['rescheduled_from'] ?? null, $a['rescheduled_to'] ?? null])->filter()->unique()->all();
        $starts = $otherIds ? DB::table('activities')->whereIn('id', $otherIds)->pluck('schedule_from', 'id') : collect();

        $describe = function ($id) use ($starts) {
            if (! $id || ! isset($starts[$id])) {
                return null;
            }

            $from = Carbon::parse($starts[$id]);

            // date_iso: el calendario abre esa cita yendo a su día si no está cargada.
            return ['id' => (int) $id, 'date' => $from->format('d/m/Y'), 'date_iso' => $from->toDateString(), 'time' => $from->format('H:i')];
        };

        $out = [];

        foreach ($activityIds as $id) {
            $additional = $links[$id] ?? [];

            $out[$id] = [
                'rescheduled_from' => $describe($additional['rescheduled_from'] ?? null),
                'rescheduled_to'   => $describe($additional['rescheduled_to'] ?? null),
            ];
        }

        return $out;
    }
}
