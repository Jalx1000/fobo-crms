<?php

namespace Webkul\Admin\Services;

use Webkul\Activity\Enums\AppointmentStatus;
use Webkul\Activity\Models\Activity;

/**
 * Transiciones explícitas del estado de una cita. El estado `completed` se
 * deriva solo de is_done (observer en Activity); acá van las que requieren
 * intención: confirmar, cancelar y reprogramar (encadenando old → new).
 *
 * Lo llaman los endpoints del agente/staff (confirmar por SÍ del recordatorio,
 * cancelar/reprogramar por texto). No pisa estados ya terminales.
 */
class AppointmentStatusService
{
    protected const TERMINAL = [
        AppointmentStatus::CANCELLED,
        AppointmentStatus::NO_SHOW,
        AppointmentStatus::RESCHEDULED,
        AppointmentStatus::COMPLETED,
    ];

    /**
     * La paciente confirmó su asistencia (ej. respondió SÍ al recordatorio).
     */
    public function confirm(Activity $activity): Activity
    {
        return $this->transition($activity, AppointmentStatus::CONFIRMED);
    }

    /**
     * La cita se cancela.
     */
    public function cancel(Activity $activity): Activity
    {
        $activity->status = AppointmentStatus::CANCELLED;
        $activity->save();

        return $activity;
    }

    /**
     * La cita se atendió. is_done=1 → el observer deriva `completed`.
     */
    public function complete(Activity $activity): Activity
    {
        $activity->is_done = 1;
        $activity->save();

        return $activity;
    }

    /**
     * Marca $old como reprogramada hacia $new (deja el rastro del encadenado).
     * El $new queda como una cita nueva (scheduled).
     */
    public function reschedule(Activity $old, Activity $new): Activity
    {
        $old->rescheduled_to_activity_id = $new->id;
        $old->status = AppointmentStatus::RESCHEDULED;
        $old->save();

        return $old;
    }

    /**
     * Transición que respeta estados terminales (no revive una cita cerrada).
     */
    protected function transition(Activity $activity, string $status): Activity
    {
        if (in_array($activity->status, self::TERMINAL, true)) {
            return $activity;
        }

        $activity->status = $status;
        $activity->save();

        return $activity;
    }
}
