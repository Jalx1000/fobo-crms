<?php

namespace Webkul\Whatsapp\Services\Flows;

use Webkul\Admin\Services\BookingOptions;
use Webkul\Admin\Services\PatientAppointments;
use Webkul\Whatsapp\Jobs\RescheduleFlowAppointment;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Formulario "Reprogramar cita" (Resources/flows/reprogramar_cita.json).
 *
 *   APPT_SELECT ──► SLOT_DATE ──► SLOT_TIME ──► SUMMARY ──► SUCCESS
 *   APPT_SELECT ──(sin citas / sin horarios con ese doctor)──► SUMMARY ──► SUCCESS
 *
 * Mismo doctor, turnos de 1 hora en punto del catálogo precalculado. El cambio
 * se aplica en segundo plano (RescheduleFlowAppointment), que revalida en vivo
 * con SMD antes de mover la cita.
 */
class RescheduleFlow
{
    use FlowScreens;

    public const KEY = 'reprogramar_cita';

    public function __construct(
        protected PatientAppointments $appointments,
        protected BookingOptions $booking,
    ) {}

    public function handle(array $request): array
    {
        $token = (string) ($request['flow_token'] ?? '');
        $conversation = ConversationProxy::modelClass()::find((int) FlowToken::conversationId($token));
        $responsible = $conversation?->person_id ? (int) $conversation->person_id : null;

        if (! $responsible) {
            return $this->select([], 'No pudimos identificarte. Escribinos por el chat.');
        }

        $data = (array) ($request['data'] ?? []);

        if (in_array($request['action'] ?? null, ['INIT', 'BACK'], true)) {
            return $this->select($this->appointments->upcomingFor($responsible));
        }

        return match ($request['screen'] ?? null) {
            'APPT_SELECT' => $this->afterSelect($token, $responsible, $data),
            'SLOT_DATE'   => $this->afterDate($token, $data),
            'SLOT_TIME'   => $this->afterTime($token, $data),
            'SUMMARY'     => $this->afterSummary($token, $responsible, $conversation->id),
            default       => $this->select($this->appointments->upcomingFor($responsible)),
        };
    }

    protected function select(array $appointments, ?string $error = null): array
    {
        $options = array_map(fn ($a) => [
            'id'          => (string) $a['activity_id'],
            'title'       => $this->label($a['schedule_from']),
            'description' => mb_substr($a['patient_name'].' · '.$a['doctor_name'], 0, 70),
        ], $appointments);

        if (! $options) {
            $options = [['id' => 'none', 'title' => 'No tenés citas próximas', 'description' => 'Tocá Siguiente para salir']];
        }

        return $this->screen('APPT_SELECT', [
            'heading'      => $appointments ? '¿Qué cita querés cambiar?' : 'No encontramos citas próximas',
            'appointments' => $options,
        ], $error);
    }

    protected function afterSelect(string $token, int $responsible, array $data): array
    {
        if (($data['appointment'] ?? '') === 'none') {
            FlowSession::merge($token, ['mode' => 'none']);

            return $this->screen('SUMMARY', [
                'heading' => 'No hay citas para cambiar',
                'detail'  => 'Si creés que es un error, escribinos por el chat.',
            ]);
        }

        $appointment = $this->appointments->findFor($responsible, (int) ($data['appointment'] ?? 0));

        if (! $appointment) {
            return $this->select($this->appointments->upcomingFor($responsible), 'Elegí una cita de la lista.');
        }

        // 1 consulta a SMD por la especialidad del doctor de la cita (se espera
        // la respuesta como en el formulario de agendar).
        $avail = $appointment['doctor_id']
            ? $this->booking->doctorAvailability((int) $appointment['doctor_id'], microtime(true) + max(0, (float) config('whatsapp.flows.booking.wait_seconds', 7)))
            : ['failed' => false, 'slots' => []];

        if ($avail === null) {
            return $this->select($this->appointments->upcomingFor($responsible), 'Estamos buscando los horarios. Tocá Siguiente de nuevo en unos segundos.');
        }

        if ($avail['failed']) {
            return $this->select($this->appointments->upcomingFor($responsible), 'No pudimos traer los horarios ahora. Probá en unos minutos o escribinos por el chat.');
        }

        $slots = $this->withoutCurrent($appointment, $avail['slots']);

        // Lo consultado queda en la sesión: los pasos siguientes no consultan SMD.
        FlowSession::merge($token, ['slots' => $slots]);

        if (! $slots) {
            FlowSession::merge($token, ['mode' => 'handoff', 'activity_id' => $appointment['activity_id']]);

            return $this->screen('SUMMARY', [
                'heading' => 'No hay horarios libres con '.$appointment['doctor_name'],
                'detail'  => 'Tocá Confirmar y una persona de recepción te ayuda a cambiarla por el chat.',
            ]);
        }

        FlowSession::merge($token, ['mode' => 'reschedule', 'activity_id' => $appointment['activity_id'], 'date' => null, 'time' => null]);

        return $this->dateScreen($slots);
    }

    protected function afterDate(string $token, array $data): array
    {
        $appointment = $this->sessionAppointment($token);
        $slots = $appointment ? $this->freeSlots($token) : [];
        $date = (string) ($data['date'] ?? '');

        if (! isset($slots[$date])) {
            return $this->dateScreen($slots, 'Elegí un día de la lista.');
        }

        FlowSession::merge($token, ['date' => $date, 'time' => null]);

        return $this->timeScreen($date, $slots[$date]);
    }

    protected function afterTime(string $token, array $data): array
    {
        $appointment = $this->sessionAppointment($token);
        $slots = $appointment ? $this->freeSlots($token) : [];
        $date = (string) (FlowSession::get($token)['date'] ?? '');
        $time = (string) ($data['time'] ?? '');

        if (! in_array($time, $slots[$date] ?? [], true)) {
            return $this->timeScreen($date, $slots[$date] ?? [], 'Elegí un horario de la lista.');
        }

        FlowSession::merge($token, ['time' => $time]);

        return $this->screen('SUMMARY', [
            'heading' => 'Revisá el cambio',
            'detail'  => implode("\n", [
                'Paciente: '.$appointment['patient_name'],
                'Doctor/a: '.$appointment['doctor_name'],
                'Antes: '.$this->label($appointment['schedule_from']),
                'Ahora: '.$this->label($date.' '.$time),
            ]),
        ]);
    }

    protected function afterSummary(string $token, int $responsible, int $conversationId): array
    {
        $session = FlowSession::get($token);
        $mode = $session['mode'] ?? null;

        if ($mode === 'none' || $mode === 'handoff') {
            FlowSession::forget($token);

            return $this->finish($token, self::KEY, [
                'status'      => $mode,
                'activity_id' => $session['activity_id'] ?? null,
            ]);
        }

        $appointment = $this->appointments->findFor($responsible, (int) ($session['activity_id'] ?? 0));
        $date = (string) ($session['date'] ?? '');
        $time = (string) ($session['time'] ?? '');

        if (! $appointment || ! in_array($time, $this->freeSlots($token)[$date] ?? [], true)) {
            return $this->screen('SUMMARY', [
                'heading' => 'Ese horario ya no está disponible',
                'detail'  => 'Volvé atrás y elegí otro horario.',
            ], 'Ese horario se acaba de ocupar. Elegí otro.');
        }

        // Se reserva ya en el catálogo; el job revalida en vivo y mueve la cita.
        $this->booking->takeSlot((int) $appointment['doctor_id'], $date, $time);

        RescheduleFlowAppointment::dispatch($appointment['activity_id'], $conversationId, $date, $time, $this->booking->duration())
            ->onQueue(config('whatsapp.queue'));

        FlowSession::forget($token);

        return $this->finish($token, self::KEY, [
            'status'        => 'pending',
            'activity_id'   => $appointment['activity_id'],
            'patient_id'    => $appointment['patient_id'],
            'patient_name'  => $appointment['patient_name'],
            'doctor_name'   => $appointment['doctor_name'],
            'previous_date' => substr($appointment['schedule_from'], 0, 10),
            'previous_time' => substr($appointment['schedule_from'], 11, 5),
            'date'          => $date,
            'time'          => $time,
        ]);
    }

    /** Horarios libres consultados al elegir la cita (guardados en la sesión). */
    protected function freeSlots(string $token): array
    {
        return (array) (FlowSession::get($token)['slots'] ?? []);
    }

    /**
     * Horarios libres del doctor de la cita, sin el horario que ya tiene.
     */
    protected function withoutCurrent(array $appointment, array $slots): array
    {
        $currentDate = substr($appointment['schedule_from'], 0, 10);
        $currentTime = substr($appointment['schedule_from'], 11, 5);

        if (isset($slots[$currentDate])) {
            $slots[$currentDate] = array_values(array_diff($slots[$currentDate], [$currentTime]));

            if (! $slots[$currentDate]) {
                unset($slots[$currentDate]);
            }
        }

        return $slots;
    }

    protected function sessionAppointment(string $token): ?array
    {
        $session = FlowSession::get($token);
        $conversation = ConversationProxy::modelClass()::find((int) FlowToken::conversationId($token));

        return ($conversation?->person_id && ! empty($session['activity_id']))
            ? $this->appointments->findFor((int) $conversation->person_id, (int) $session['activity_id'])
            : null;
    }

    protected function dateScreen(array $slots, ?string $error = null): array
    {
        return $this->screen('SLOT_DATE', [
            'dates' => array_map(fn ($date) => ['id' => $date, 'title' => $this->dayLabel($date)], array_keys($slots)),
        ], $error);
    }

    protected function timeScreen(string $date, array $times, ?string $error = null): array
    {
        return $this->screen('SLOT_TIME', [
            'heading' => 'Horarios del '.$this->dayLabel($date),
            'times'   => array_map(fn ($t) => ['id' => $t, 'title' => $t], $times),
        ], $error);
    }
}
