<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Las próximas citas de una persona y de los pacientes que tiene a cargo, y
 * las dos acciones que se pueden hacer sobre ellas desde WhatsApp: cancelar y
 * reprogramar. Ambas empujan el cambio a SMD (SmdAppointmentPush), que es la
 * fuente de verdad: sin eso, la próxima sincronización lo revertiría.
 */
class PatientAppointments
{
    public function __construct(
        protected PatientIdentityResolver $identities,
        protected SmdAppointmentPush $push,
    ) {}

    /**
     * Citas futuras y vigentes (no canceladas, atendidas ni perdidas) de la
     * persona y de sus pacientes a cargo, la más próxima primero.
     *
     * @return array<int, array{activity_id:int, patient_id:int, patient_name:string, doctor_id:?int, doctor_name:string, schedule_from:string, schedule_to:string, lead_id:?int}>
     */
    public function upcomingFor(int $responsibleId): array
    {
        $people = array_merge([$responsibleId], array_column($this->identities->patientsOf($responsibleId), 'id'));

        $closedStages = array_filter([
            (int) config('smd.stage_map.cancelled'),
            (int) config('smd.stage_map.completed'),
            (int) config('smd.stage_map.no_show'),
        ]);

        return DB::table('activities')
            ->join('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
            ->join('persons', 'persons.id', '=', 'activity_participants.person_id')
            ->leftJoin('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->leftJoin('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->leftJoin('doctor_activities', 'doctor_activities.activity_id', '=', 'activities.id')
            ->leftJoin('doctors', 'doctors.id', '=', 'doctor_activities.doctor_id')
            ->whereIn('activity_participants.person_id', $people)
            ->where('activities.type', 'meeting')
            ->where('activities.is_done', 0)
            ->where('activities.schedule_from', '>', now(config('app.timezone'))->format('Y-m-d H:i:s'))
            ->where(function ($q) use ($closedStages) {
                $q->whereNull('leads.id')
                    ->orWhereNull('leads.lead_pipeline_stage_id')
                    ->orWhereNotIn('leads.lead_pipeline_stage_id', $closedStages);
            })
            ->orderBy('activities.schedule_from')
            ->get([
                'activities.id as activity_id',
                'activities.schedule_from',
                'activities.schedule_to',
                'persons.id as patient_id',
                'persons.name as patient_name',
                'doctors.id as doctor_id',
                'doctors.name as doctor_name',
                'leads.id as lead_id',
            ])
            ->unique('activity_id')
            ->map(fn ($a) => [
                'activity_id'   => (int) $a->activity_id,
                'patient_id'    => (int) $a->patient_id,
                'patient_name'  => trim((string) $a->patient_name),
                'doctor_id'     => $a->doctor_id ? (int) $a->doctor_id : null,
                'doctor_name'   => trim((string) $a->doctor_name),
                'schedule_from' => (string) $a->schedule_from,
                'schedule_to'   => (string) $a->schedule_to,
                'lead_id'       => $a->lead_id ? (int) $a->lead_id : null,
            ])
            ->values()
            ->all();
    }

    /**
     * ¿Paciente nuevo para la clínica? Hay que llamarlo ANTES de crear la cita:
     * al agendar, AppointmentService le da de alta en SMD y deja de parecer nuevo.
     *
     * Nuevo = no está en SMD (ahí está todo el historial, también el anterior al
     * CRM) y no tiene en el CRM ninguna cita que no se haya cancelado. Una cita
     * cancelada o en la que no se presentó no lo hace conocido: nunca vino.
     */
    public function isNewPatient(int $personId): bool
    {
        if (DB::table('persons')->where('id', $personId)->whereNotNull('smd_patient_id')->where('smd_patient_id', '!=', '')->exists()) {
            return false;
        }

        $cancelledStages = array_filter([
            (int) config('smd.stage_map.cancelled'),
            (int) config('smd.stage_map.no_show'),
        ]);

        return ! DB::table('activities')
            ->join('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
            ->leftJoin('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->leftJoin('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->where('activity_participants.person_id', $personId)
            ->where('activities.type', 'meeting')
            ->where(function ($q) use ($cancelledStages) {
                $q->whereNull('leads.id')
                    ->orWhere(fn ($q) => $q->where(fn ($q) => $q->whereNull('leads.status')->orWhere('leads.status', '!=', 0))
                        ->where(fn ($q) => $q->whereNull('leads.lead_pipeline_stage_id')->orWhereNotIn('leads.lead_pipeline_stage_id', $cancelledStages)));
            })
            ->exists();
    }

    /**
     * La cita, solo si es una de las próximas de este responsable. El id viene
     * del teléfono: nunca se confía en él sin este control.
     */
    public function findFor(int $responsibleId, int $activityId): ?array
    {
        foreach ($this->upcomingFor($responsibleId) as $appointment) {
            if ($appointment['activity_id'] === $activityId) {
                return $appointment;
            }
        }

        return null;
    }

    /**
     * Cancela: el lead pasa a "Cita Cancelada" y el cambio se empuja a SMD.
     *
     * @return array{synced: bool, reason: ?string}
     */
    public function cancel(int $activityId, ?string $reason = null): array
    {
        $leadIds = DB::table('lead_activities')->where('activity_id', $activityId)->pluck('lead_id');

        if ($leadIds->isNotEmpty()) {
            DB::table('leads')->whereIn('id', $leadIds)->update([
                'lead_pipeline_stage_id' => (int) config('smd.stage_map.cancelled'),
                'status'                 => 0,
                'lost_reason'            => 'Cancelada por el paciente (WhatsApp)'.($reason ? ': '.$reason : ''),
                'closed_at'              => now(),
                'updated_at'             => now(),
            ]);
        }

        $result = $this->push->push($activityId);

        Log::info('[Citas] Cita cancelada desde WhatsApp', ['activity_id' => $activityId, 'smd' => $result]);

        return $result;
    }

    /**
     * Recordatorios mandados a esta conversación de citas que todavía no
     * pasaron, el más reciente primero. Es lo que le dice al agente que un "Sí"
     * suelto contesta a un recordatorio, y de qué cita.
     *
     * @return array<int, array{activity_id:int, lead_id:?int, paciente:string, fecha:string, hora:string, doctor:string, enviado_at:?string, respuesta:?string}>
     */
    public function remindersOf(int $conversationId): array
    {
        return DB::table('whatsapp_appointment_reminders as r')
            ->join('activities', 'activities.id', '=', 'r.activity_id')
            ->leftJoin('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
            ->leftJoin('persons', 'persons.id', '=', 'activity_participants.person_id')
            ->leftJoin('doctor_activities', 'doctor_activities.activity_id', '=', 'activities.id')
            ->leftJoin('doctors', 'doctors.id', '=', 'doctor_activities.doctor_id')
            ->leftJoin('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->where('r.conversation_id', $conversationId)
            ->where('activities.schedule_from', '>', now(config('app.timezone'))->format('Y-m-d H:i:s'))
            ->orderByDesc('r.sent_at')
            ->get([
                'activities.id as activity_id', 'activities.schedule_from', 'lead_activities.lead_id',
                'persons.name as paciente', 'doctors.name as doctor', 'r.sent_at', 'r.response',
            ])
            ->unique('activity_id')
            ->map(fn ($r) => [
                'activity_id' => (int) $r->activity_id,
                'lead_id'     => $r->lead_id ? (int) $r->lead_id : null,
                'paciente'    => trim((string) $r->paciente),
                'fecha'       => Carbon::parse($r->schedule_from)->format('d/m/Y'),
                'hora'        => Carbon::parse($r->schedule_from)->format('H:i'),
                'doctor'      => trim((string) $r->doctor),
                'enviado_at'  => $r->sent_at ? Carbon::parse($r->sent_at)->toIso8601String() : null,
                'respuesta'   => $r->response,
            ])
            ->values()
            ->all();
    }

    /**
     * Respuesta del paciente al recordatorio, registrada por el agente.
     *
     * "confirma": el lead pasa a "Cita confirmada" y SMD recibe CONFIRMED, igual
     * que si recepción la marcara desde el calendario. "no_asiste": no se toca
     * la cita (cancelarla o moverla va por los formularios); queda anotado para
     * que recepción llame. En los dos casos se deja una nota en el lead de la
     * cita, que es donde recepción la ve.
     *
     * @return array{synced: bool, reason: ?string}
     */
    public function respond(int $activityId, string $response, ?int $userId = null): array
    {
        $at = now();
        $leadIds = DB::table('lead_activities')->where('activity_id', $activityId)->pluck('lead_id');

        $additional = json_decode((string) DB::table('activities')->where('id', $activityId)->value('additional'), true) ?: [];
        DB::table('activities')->where('id', $activityId)->update([
            'additional' => json_encode(array_merge($additional, [
                'patient_response' => ['respuesta' => $response, 'at' => $at->toIso8601String(), 'canal' => 'whatsapp'],
            ])),
        ]);

        DB::table('whatsapp_appointment_reminders')->where('activity_id', $activityId)
            ->update(['response' => $response, 'responded_at' => $at, 'updated_at' => $at]);

        $result = ['synced' => false, 'reason' => 'sin cambios en SMD'];

        if ($response === 'confirma') {
            if ($leadIds->isNotEmpty()) {
                DB::table('leads')->whereIn('id', $leadIds)->update([
                    'lead_pipeline_stage_id' => (int) config('smd.stage_map.confirmed'),
                    'updated_at'             => $at,
                ]);
            }

            $result = $this->push->push($activityId);
        }

        $note = $response === 'confirma'
            ? 'El paciente CONFIRMÓ su asistencia por WhatsApp (respuesta al recordatorio).'
            : 'El paciente avisó por WhatsApp que NO va a asistir. La cita sigue en pie: llamar para reprogramar o cancelar.';

        foreach ($leadIds as $leadId) {
            $noteId = DB::table('activities')->insertGetId([
                'type'       => 'note',
                'comment'    => $note,
                'is_done'    => 1,
                'user_id'    => $userId ?: 1,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            DB::table('lead_activities')->insert(['lead_id' => $leadId, 'activity_id' => $noteId]);
        }

        Log::info('[Citas] Respuesta del paciente al recordatorio', ['activity_id' => $activityId, 'respuesta' => $response, 'smd' => $result]);

        return $result;
    }

    /**
     * Reprograma: mueve el horario (mismo doctor y paciente) y lo empuja a SMD.
     *
     * @return array{synced: bool, reason: ?string}
     */
    public function reschedule(int $activityId, Carbon $from, int $minutes): array
    {
        DB::table('activities')->where('id', $activityId)->update([
            'schedule_from' => $from->format('Y-m-d H:i:s'),
            'schedule_to'   => $from->copy()->addMinutes($minutes)->format('Y-m-d H:i:s'),
            'updated_at'    => now(),
        ]);

        $result = $this->push->push($activityId);

        Log::info('[Citas] Cita reprogramada desde WhatsApp', [
            'activity_id' => $activityId, 'nuevo_horario' => $from->toDateTimeString(), 'smd' => $result,
        ]);

        return $result;
    }
}
