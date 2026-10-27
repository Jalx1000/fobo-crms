<?php

namespace Webkul\Activity\Enums;

/**
 * Estados de una cita de Tessa. Se guardan como string en activities.status.
 */
final class AppointmentStatus
{
    public const SCHEDULED = 'scheduled';     // agendada

    public const CONFIRMED = 'confirmed';     // confirmada por la paciente

    public const COMPLETED = 'completed';     // atendida

    public const NO_SHOW = 'no_show';         // faltó sin aviso

    public const CANCELLED = 'cancelled';     // cancelada

    public const RESCHEDULED = 'rescheduled'; // reprogramada (ver rescheduled_to_activity_id)

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::SCHEDULED,
            self::CONFIRMED,
            self::COMPLETED,
            self::NO_SHOW,
            self::CANCELLED,
            self::RESCHEDULED,
        ];
    }
}
