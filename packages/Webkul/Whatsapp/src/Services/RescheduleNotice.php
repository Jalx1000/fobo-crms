<?php

namespace Webkul\Whatsapp\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Whatsapp\Gateways\Dto\MessagingWindow;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Aviso por WhatsApp de una cita reprogramada desde el CRM (planning/17, D8–D9).
 *
 * Va a quien agendó (un padre que sacó la cita de su hijo) o al paciente, igual
 * que los recordatorios. Dentro de la ventana de 24 h de Meta sale un texto;
 * fuera de ella, la plantilla `cita_reprogramada` solo si la bandera
 * whatsapp.reschedule.template_enabled está encendida. Si no, no se manda nada
 * y recepción avisa por su cuenta.
 */
class RescheduleNotice
{
    public function __construct(
        protected GatewayManager $gateways,
        protected ConversationResolver $resolver,
    ) {}

    /**
     * Cómo se avisaría hoy a quien corresponde esta cita, sin mandar nada.
     *
     * @return 'text'|'template'|false
     */
    public function mode(int $activityId): string|false
    {
        return $this->plan($activityId)['mode'];
    }

    /**
     * Avisa la fecha nueva de la cita (la nueva, ya creada).
     *
     * @return 'text'|'template'|false
     */
    public function send(int $newActivityId): string|false
    {
        $plan = $this->plan($newActivityId);

        if (! $plan['mode']) {
            Log::info('[Citas] Reprogramada sin aviso por WhatsApp', ['activity_id' => $newActivityId, 'motivo' => $plan['reason']]);

            return false;
        }

        $appt = $this->appointment($newActivityId);
        $recipient = $plan['recipient'];
        $conversation = $plan['conversation'] ?? $this->resolver->findOrCreate($plan['phone'], $recipient->name);
        $when = $this->longDate($appt['from']).' a horas '.$appt['from']->format('H:i');
        $name = trim((string) $recipient->name);

        $message = MessageProxy::modelClass()::create($plan['mode'] === 'text'
            ? [
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'text',
                'body'            => $this->text($name, $appt, $when, (int) $recipient->id),
                'status'          => 'queued',
                'sender'          => 'system',
            ]
            : [
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'template',
                // Vista previa legible para el inbox; el envío real usa la plantilla.
                'body'            => "Hola {$name}, le informamos que su cita en la Clínica Odontoking fue reprogramada para el {$when} con el Dr(a). {$appt['doctor']}. Si necesita otro horario, responda a este mensaje.",
                'status'          => 'queued',
                'sender'          => 'system',
                'payload'         => [
                    'template'  => config('whatsapp.reschedule.template_key', 'appointment_rescheduled'),
                    'variables' => [$name, $when, $appt['doctor']],
                ],
            ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id, $plan['mode'] === 'template' ? config('whatsapp.reschedule.gateway') : null)
            ->onQueue(config('whatsapp.queue'));

        return $plan['mode'];
    }

    /**
     * @return array{mode: 'text'|'template'|false, reason: ?string, recipient?: object, phone?: ?string, conversation?: mixed}
     */
    protected function plan(int $activityId): array
    {
        $additional = json_decode((string) DB::table('activities')->where('id', $activityId)->value('additional'), true) ?: [];
        $recipientId = (int) ($additional['booked_by_person_id'] ?? 0)
            ?: (int) DB::table('activity_participants')->where('activity_id', $activityId)->whereNotNull('person_id')->value('person_id');

        $recipient = $recipientId ? PersonProxy::modelClass()::find($recipientId) : null;

        if (! $recipient) {
            return ['mode' => false, 'reason' => 'sin paciente'];
        }

        $conversation = ConversationProxy::modelClass()::where('person_id', $recipient->id)
            ->orderByDesc('last_inbound_at')
            ->first();

        $phone = $this->phoneOf($recipient);

        if ($conversation) {
            $window = MessagingWindow::compute(
                $this->gateways->outbound($conversation)->messagingWindowHours(),
                $conversation->last_inbound_at,
            );

            if (! $window->applies || $window->open) {
                return ['mode' => 'text', 'reason' => null, 'recipient' => $recipient, 'conversation' => $conversation];
            }
        }

        if (! config('whatsapp.reschedule.template_enabled')) {
            return ['mode' => false, 'reason' => 'fuera de la ventana de 24 h'];
        }

        if (! $conversation && ! $phone) {
            return ['mode' => false, 'reason' => 'sin teléfono'];
        }

        return ['mode' => 'template', 'reason' => null, 'recipient' => $recipient, 'conversation' => $conversation, 'phone' => $phone];
    }

    /** @return array{from: Carbon, doctor: string, patient_id: int, patient: string} */
    protected function appointment(int $activityId): array
    {
        $row = DB::table('activities')
            ->leftJoin('doctor_activities', 'doctor_activities.activity_id', '=', 'activities.id')
            ->leftJoin('doctors', 'doctors.id', '=', 'doctor_activities.doctor_id')
            ->leftJoin('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
            ->leftJoin('persons', 'persons.id', '=', 'activity_participants.person_id')
            ->where('activities.id', $activityId)
            ->first(['activities.schedule_from', 'doctors.name as doctor', 'persons.id as patient_id', 'persons.name as patient']);

        return [
            'from'       => Carbon::parse($row->schedule_from),
            // Una sola línea: Meta rechaza parámetros con saltos (error 132018).
            'doctor'     => trim(preg_replace('/\s+/u', ' ', (string) $row->doctor)),
            'patient_id' => (int) $row->patient_id,
            'patient'    => trim((string) $row->patient),
        ];
    }

    protected function text(string $name, array $appt, string $when, int $recipientId): string
    {
        $whose = $recipientId === $appt['patient_id'] ? 'su cita' : "la cita de {$appt['patient']}";

        return "Hola {$name}, le informamos que {$whose} en la Clínica Odontoking fue reprogramada para el {$when} con el Dr(a). {$appt['doctor']}."
            .' Si necesita otro horario, responda a este mensaje.';
    }

    /** Mismo formato que el recordatorio: "Martes 3 de Noviembre". */
    protected function longDate(Carbon $d): string
    {
        $days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        $months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        return $days[$d->dayOfWeek].' '.$d->day.' de '.$months[$d->month - 1];
    }

    protected function phoneOf($person): ?string
    {
        foreach ((array) $person->contact_numbers as $number) {
            $value = is_array($number) ? ($number['value'] ?? null) : $number;

            if ($value) {
                return (string) $value;
            }
        }

        return null;
    }
}
