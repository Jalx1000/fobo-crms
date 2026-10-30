<?php

namespace Webkul\Whatsapp\Services\Flows;

use Webkul\Admin\Services\PatientAppointments;
use Webkul\Whatsapp\Jobs\CancelFlowAppointment;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Formulario "Cancelar cita" (Resources/flows/cancelar_cita.json).
 *
 *   APPT_SELECT (próximas citas propias y de pacientes a cargo) ──► CONFIRM_CANCEL ──► SUCCESS
 *
 * La cancelación se hace en segundo plano (CancelFlowAppointment) y el
 * resultado llega por el chat: empujar el cambio a SMD puede tardar más de lo
 * que Meta espera la respuesta.
 */
class CancelFlow
{
    use FlowScreens;

    public const KEY = 'cancelar_cita';

    public const REASONS = [
        ['id' => 'no_puedo', 'title' => 'No puedo asistir'],
        ['id' => 'ya_no', 'title' => 'Ya no la necesito'],
        ['id' => 'otro_dia', 'title' => 'Prefiero otro día'],
        ['id' => 'otro', 'title' => 'Otro motivo'],
    ];

    public function __construct(protected PatientAppointments $appointments) {}

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
            'APPT_SELECT'    => $this->afterSelect($token, $responsible, $data),
            'CONFIRM_CANCEL' => $this->afterConfirm($token, $responsible, $conversation->id, $data),
            default          => $this->select($this->appointments->upcomingFor($responsible)),
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
            'heading'      => $appointments ? '¿Qué cita querés cancelar?' : 'No encontramos citas próximas',
            'appointments' => $options,
        ], $error);
    }

    protected function afterSelect(string $token, int $responsible, array $data): array
    {
        if (($data['appointment'] ?? '') === 'none') {
            FlowSession::merge($token, ['mode' => 'none']);

            return $this->screen('CONFIRM_CANCEL', [
                'heading' => 'No hay citas para cancelar',
                'detail'  => 'Si creés que es un error, escribinos por el chat.',
                'reasons' => self::REASONS,
            ]);
        }

        $appointment = $this->appointments->findFor($responsible, (int) ($data['appointment'] ?? 0));

        if (! $appointment) {
            return $this->select($this->appointments->upcomingFor($responsible), 'Elegí una cita de la lista.');
        }

        FlowSession::merge($token, ['mode' => 'cancel', 'activity_id' => $appointment['activity_id']]);

        return $this->screen('CONFIRM_CANCEL', [
            'heading' => '¿Cancelar esta cita?',
            'detail'  => implode("\n", [
                'Paciente: '.$appointment['patient_name'],
                'Doctor/a: '.$appointment['doctor_name'],
                'Fecha: '.$this->label($appointment['schedule_from']),
            ]),
            'reasons' => self::REASONS,
        ]);
    }

    protected function afterConfirm(string $token, int $responsible, int $conversationId, array $data): array
    {
        $session = FlowSession::get($token);

        if (($session['mode'] ?? null) === 'none') {
            FlowSession::forget($token);

            return $this->finish($token, self::KEY, ['status' => 'none']);
        }

        // Se revalida: el id viene de la sesión, pero la cita pudo cambiar.
        $appointment = $this->appointments->findFor($responsible, (int) ($session['activity_id'] ?? 0));

        if (! $appointment) {
            return $this->screen('CONFIRM_CANCEL', [
                'heading' => 'Esa cita ya no se puede cancelar',
                'detail'  => 'Puede que ya esté cancelada o haya pasado. Escribinos por el chat.',
                'reasons' => self::REASONS,
            ], 'Esa cita ya no se puede cancelar.');
        }

        $reason = collect(self::REASONS)->firstWhere('id', $data['reason'] ?? '')['title'] ?? null;

        CancelFlowAppointment::dispatch($appointment['activity_id'], $conversationId, $reason)
            ->onQueue(config('whatsapp.queue'));

        FlowSession::forget($token);

        return $this->finish($token, self::KEY, [
            'status'       => 'pending',
            'activity_id'  => $appointment['activity_id'],
            'patient_id'   => $appointment['patient_id'],
            'patient_name' => $appointment['patient_name'],
            'doctor_name'  => $appointment['doctor_name'],
            'date'         => substr($appointment['schedule_from'], 0, 10),
            'time'         => substr($appointment['schedule_from'], 11, 5),
            'reason'       => $reason,
        ]);
    }
}
