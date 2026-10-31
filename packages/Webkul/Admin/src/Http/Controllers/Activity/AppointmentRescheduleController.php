<?php

namespace Webkul\Admin\Http\Controllers\Activity;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Admin\Exceptions\AppointmentException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Services\AppointmentRescheduler;
use Webkul\Admin\Services\AvailabilityResolverService;
use Webkul\Admin\Services\BookingOptions;
use Webkul\Whatsapp\Services\RescheduleNotice;

/**
 * Botón "Reprogramar" del calendario (planning/17, D7–D9): los horarios libres
 * reales del doctor de la cita y la reprogramación (cancela + crea).
 */
class AppointmentRescheduleController extends Controller
{
    /** Días que se muestran por pantalla (una semana; el calendario navega por semanas). */
    public const DAYS = 7;

    public function __construct(
        protected AvailabilityResolverService $availability,
        protected BookingOptions $booking,
        protected RescheduleNotice $notice,
    ) {}

    /**
     * Horarios libres para reprogramar: SMD ∩ turnos − citas, en pasos de la
     * duración de la cita, sin el horario actual. Si SMD no responde no se
     * ofrece nada: un horario sin validar se caería al confirmar.
     */
    public function slots(int $id): JsonResponse
    {
        $data = request()->validate(['from' => 'nullable|date_format:Y-m-d']);
        $appointment = $this->appointment($id);
        $tz = config('app.timezone');

        $today = now($tz)->startOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : $today->copy();
        $from = $from->lt($today) ? $today : $from;

        try {
            $result = $this->availability->resolve($appointment['doctor_id'], $from, self::DAYS, ['duration_minutes' => 0]);
        } catch (Throwable $e) {
            $result = ['source' => 'error', 'reason' => 'smd_unavailable'];
        }

        // Con SMD desactivado (smd.validate_availability=false) la cita tampoco
        // se valida allá al crearla: la agenda local es la referencia.
        $failed = ($result['source'] ?? null) !== 'smd' && ($result['reason'] ?? null) !== 'smd_disabled';
        $times = $failed ? [] : $this->booking->startTimes($result['schedule'] ?? [], $appointment['duration']);

        $days = [];

        for ($day = $from->copy(); $day->lt($from->copy()->addDays(self::DAYS)); $day->addDay()) {
            $date = $day->toDateString();
            $dayTimes = $times[$date] ?? [];

            if ($date === $appointment['from']->toDateString()) {
                $dayTimes = array_values(array_diff($dayTimes, [$appointment['from']->format('H:i')]));
            }

            $days[] = [
                'date'  => $date,
                'label' => $day->copy()->locale('es')->isoFormat('ddd D/M'),
                'times' => $dayTimes,
            ];
        }

        return response()->json([
            'days'     => $days,
            'from'     => $from->toDateString(),
            'duration' => $appointment['duration'],
            'failed'   => $failed,
            'notify'   => $this->notice->mode($id),
        ]);
    }

    /**
     * Reprograma: cancela la cita y crea una nueva en el horario elegido, y
     * avisa al paciente por WhatsApp si corresponde.
     */
    public function store(int $id, AppointmentRescheduler $rescheduler): JsonResponse
    {
        $data = request()->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $appointment = $this->appointment($id);
        $from = Carbon::parse($data['date'].' '.$data['time'], config('app.timezone'));

        if ($from->lte(now(config('app.timezone')))) {
            return response()->json(['message' => 'El horario nuevo ya pasó.'], 422);
        }

        try {
            $result = $rescheduler->reschedule($id, $from, $from->copy()->addMinutes($appointment['duration']), [], 'crm');
        } catch (AppointmentException $e) {
            return response()->json(['message' => $e->getMessage(), 'details' => $e->getDetails()], 422);
        }

        try {
            $notified = $this->notice->send($result['new_activity_id']);
        } catch (Throwable $e) {
            report($e);
            $notified = false;
        }

        $old = $appointment['from']->format('d/m H:i');
        $new = $from->format('d/m H:i');

        return response()->json(array_merge($result, [
            'notified' => $notified,
            'message'  => "Cita reprogramada: la del {$old} quedó cancelada y se creó la del {$new}. "
                .match ($notified) {
                    'text'     => 'Se avisó al paciente por WhatsApp.',
                    'template' => 'Se avisó al paciente por WhatsApp (plantilla).',
                    default    => 'No se avisó por WhatsApp: fuera de las 24 h, avisale vos.',
                },
        ]));
    }

    /** @return array{doctor_id:int, from:Carbon, duration:int} */
    protected function appointment(int $id): array
    {
        $activity = DB::table('activities')->where('id', $id)->where('type', 'meeting')->first();
        $doctorId = (int) DB::table('doctor_activities')->where('activity_id', $id)->value('doctor_id');

        abort_if(! $activity || ! $doctorId, 404, 'Cita no encontrada.');

        $from = Carbon::parse($activity->schedule_from, config('app.timezone'));

        return [
            'doctor_id' => $doctorId,
            'from'      => $from,
            'duration'  => max(15, (int) $from->diffInMinutes(Carbon::parse($activity->schedule_to, config('app.timezone')))),
        ];
    }
}
