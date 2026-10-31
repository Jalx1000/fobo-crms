<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Admin\Exceptions\AppointmentException;

/**
 * Reprogramar = cancelar la cita y crear una nueva (planning/17). Es el único
 * punto de verdad para el formulario de WhatsApp y el calendario del CRM.
 *
 * Orden: validar → crear la nueva → cancelar la vieja; si la nueva falla, la
 * vieja queda intacta. Si los horarios se superponen (mismo doctor), SMD vería
 * ocupado el horario nuevo por la vieja: se cancela primero y, si la nueva
 * falla, la vieja se restaura tal como estaba (también en SMD).
 */
class AppointmentRescheduler
{
    public const LOST_REASON = 'Reprogramada';

    public function __construct(
        protected AppointmentService $appointments,
        protected PatientAppointments $patientAppointments,
        protected SmdAppointmentPush $push,
    ) {}

    /**
     * @param  array  $overrides  lo editado sobre la cita (reason, product_id, doctor_id…)
     * @return array{old_activity_id:int, new_activity_id:int, new_lead_id:int, smd:array}
     *
     * @throws AppointmentException la vieja queda como estaba
     */
    public function reschedule(int $oldActivityId, Carbon $from, Carbon $to, array $overrides = [], string $origin = 'crm'): array
    {
        $old = $this->current($oldActivityId);

        $data = array_merge([
            'doctor_id'     => $old['doctor_ids'][0],
            'doctor_ids'    => $old['doctor_ids'],
            'person'        => ['id' => $old['person_id']],
            'title'         => $old['title'],
            'reason'        => $old['comment'],
            'product_id'    => $old['product_id'],
            'contact_phone' => $old['contact_phone'],
        ], $overrides, [
            'schedule_from'        => $from->format('Y-m-d H:i:s'),
            'schedule_to'          => $to->format('Y-m-d H:i:s'),
            'replaces_activity_id' => $oldActivityId,
        ]);

        $overlaps = in_array((int) $data['doctor_id'], $old['doctor_ids'], true)
            && $from->lt($old['to']) && $to->gt($old['from']);

        if ($overlaps) {
            $snapshot = $this->leadSnapshot($oldActivityId);
            $smd = $this->patientAppointments->cancel($oldActivityId, null, self::LOST_REASON);

            try {
                $created = $this->appointments->process($data);
            } catch (Throwable $e) {
                $this->restore($oldActivityId, $snapshot);

                throw $e;
            }
        } else {
            $created = $this->appointments->process($data);
            $smd = $this->patientAppointments->cancel($oldActivityId, null, self::LOST_REASON);
        }

        $newActivityId = (int) $created['activity_id'];
        $newLeadId = (int) $created['lead_id'];

        if ($old['lead_user_id']) {
            DB::table('leads')->where('id', $newLeadId)->update(['user_id' => $old['lead_user_id']]);
        }

        $this->mergeAdditional($newActivityId, array_filter([
            'booked_by_person_id' => $old['additional']['booked_by_person_id'] ?? null,
            'source'              => $old['additional']['source'] ?? null,
            'rescheduled_from'    => $oldActivityId,
        ]));
        $this->mergeAdditional($oldActivityId, ['rescheduled_to' => $newActivityId]);

        Log::info('[Citas] Cita reprogramada (cancelada + nueva)', [
            'old_activity_id' => $oldActivityId,
            'new_activity_id' => $newActivityId,
            'nuevo_horario'   => $from->toDateTimeString(),
            'superpuesta'     => $overlaps,
            'origen'          => $origin,
            'smd'             => $smd,
        ]);

        return [
            'old_activity_id' => $oldActivityId,
            'new_activity_id' => $newActivityId,
            'new_lead_id'     => $newLeadId,
            'smd'             => $smd,
        ];
    }

    /**
     * La cita a reprogramar con todo lo que se copia a la nueva.
     *
     * @throws AppointmentException si no existe o ya está cancelada
     */
    protected function current(int $activityId): array
    {
        $activity = DB::table('activities')->where('id', $activityId)->where('type', 'meeting')->first();

        if (! $activity) {
            throw new AppointmentException('La cita a reprogramar no existe.');
        }

        $lead = DB::table('leads')
            ->join('lead_activities', 'lead_activities.lead_id', '=', 'leads.id')
            ->where('lead_activities.activity_id', $activityId)
            ->first(['leads.id', 'leads.user_id', 'leads.status', 'leads.lead_pipeline_stage_id']);

        $closedStages = array_filter([(int) config('smd.stage_map.cancelled'), (int) config('smd.stage_map.no_show')]);

        if ($lead && ((string) $lead->status === '0' || in_array((int) $lead->lead_pipeline_stage_id, $closedStages, true))) {
            throw new AppointmentException('La cita ya está cancelada: no se puede reprogramar.');
        }

        $doctorIds = DB::table('doctor_activities')->where('activity_id', $activityId)->pluck('doctor_id')->map(fn ($id) => (int) $id)->all();
        $personId = (int) DB::table('activity_participants')->where('activity_id', $activityId)->whereNotNull('person_id')->value('person_id');

        if (! $doctorIds || ! $personId) {
            throw new AppointmentException('La cita no tiene doctor o paciente: no se puede reprogramar.');
        }

        $additional = json_decode((string) $activity->additional, true) ?: [];

        return [
            'doctor_ids'    => $doctorIds,
            'person_id'     => $personId,
            'title'         => $activity->title,
            'comment'       => (string) $activity->comment,
            'product_id'    => DB::table('product_activities')->where('activity_id', $activityId)->value('product_id'),
            'additional'    => $additional,
            'lead_user_id'  => $lead->user_id ?? null,
            'from'          => Carbon::parse($activity->schedule_from),
            'to'            => Carbon::parse($activity->schedule_to),
            // Paciente sin teléfono propio (un hijo): SMD lo registra con el de
            // quien agendó, igual que al crear la cita por el formulario.
            'contact_phone' => $this->firstPhone((int) ($additional['booked_by_person_id'] ?? 0)),
        ];
    }

    /** Etapa y estado de los leads de la cita, para restaurarlos si hace falta. */
    protected function leadSnapshot(int $activityId): array
    {
        return DB::table('leads')
            ->whereIn('id', DB::table('lead_activities')->where('activity_id', $activityId)->pluck('lead_id'))
            ->get(['id', 'lead_pipeline_stage_id', 'status', 'lost_reason', 'closed_at'])
            ->map(fn ($l) => (array) $l)
            ->all();
    }

    protected function restore(int $activityId, array $snapshot): void
    {
        foreach ($snapshot as $lead) {
            DB::table('leads')->where('id', $lead['id'])->update(array_merge($lead, ['updated_at' => now()]));
        }

        $result = $this->push->push($activityId);

        Log::warning('[Citas] La cita nueva falló: se restauró la original', ['activity_id' => $activityId, 'smd' => $result]);
    }

    protected function mergeAdditional(int $activityId, array $values): void
    {
        $additional = json_decode((string) DB::table('activities')->where('id', $activityId)->value('additional'), true) ?: [];

        DB::table('activities')->where('id', $activityId)->update([
            'additional' => json_encode(array_merge($additional, $values)),
        ]);
    }

    protected function firstPhone(int $personId): ?string
    {
        if (! $personId) {
            return null;
        }

        foreach ((array) json_decode((string) DB::table('persons')->where('id', $personId)->value('contact_numbers'), true) as $number) {
            if (! empty($number['value'])) {
                return (string) $number['value'];
            }
        }

        return null;
    }
}
