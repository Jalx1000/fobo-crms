<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Estado del recordatorio de WhatsApp de cada cita, para el indicador del
 * calendario (planning/17, D10). Se deriva de whatsapp_appointment_reminders y
 * del estado que Meta reporta en whatsapp_messages: no hay columnas propias.
 *
 * El recordatorio se busca por activity_id o por el external_id de SMD, igual
 * que la idempotencia de SendAppointmentReminders: una cita que la sync recreó
 * con otro activity_id sigue mostrando el recordatorio de la original.
 */
class AppointmentReminderStatus
{
    /**
     * Respuesta del paciente → estado. Gana sobre el estado de entrega.
     */
    protected const RESPONSES = [
        'confirma'  => 'confirmed',
        'no_asiste' => 'declined',
    ];

    /**
     * Dos consultas para todas las citas, sin importar cuántas sean.
     *
     * @param  array<int, int>  $activityIds
     * @return array<int, array{reminder_status: string, reminder_sent_at: ?string, reminder_response: ?string, reminder_error: ?string}>
     */
    public function forActivities(array $activityIds): array
    {
        $activityIds = array_values(array_unique(array_filter(array_map('intval', $activityIds))));

        if (! $activityIds) {
            return [];
        }

        $externalIds = DB::table('smd_synced_events')
            ->whereIn('activity_id', $activityIds)
            ->whereNotNull('external_id')
            ->pluck('external_id', 'activity_id');

        $rows = DB::table('whatsapp_appointment_reminders as r')
            ->leftJoin('whatsapp_messages as m', 'm.id', '=', 'r.message_id')
            ->where(function ($q) use ($activityIds, $externalIds) {
                $q->whereIn('r.activity_id', $activityIds);

                if ($externalIds->isNotEmpty()) {
                    $q->orWhereIn('r.external_id', $externalIds->values()->all());
                }
            })
            ->orderByDesc('r.sent_at')
            ->get(['r.activity_id', 'r.external_id', 'r.sent_at', 'r.response', 'm.status', 'm.error']);

        $byActivity = $rows->unique('activity_id')->keyBy('activity_id');
        $byExternal = $rows->whereNotNull('external_id')->unique('external_id')->keyBy('external_id');

        $out = [];

        foreach ($activityIds as $id) {
            $external = $externalIds[$id] ?? null;
            $row = $byActivity->get($id) ?? ($external ? $byExternal->get($external) : null);

            $out[$id] = $this->present($row);
        }

        return $out;
    }

    /**
     * @return array{reminder_status: string, reminder_sent_at: ?string, reminder_response: ?string, reminder_error: ?string}
     */
    protected function present(?object $row): array
    {
        if (! $row) {
            return ['reminder_status' => 'none', 'reminder_sent_at' => null, 'reminder_response' => null, 'reminder_error' => null];
        }

        // Una fila vieja sin mensaje vinculado se dio por enviada en su momento.
        $status = self::RESPONSES[$row->response] ?? ($row->status ?: 'sent');

        return [
            'reminder_status'   => $status,
            'reminder_sent_at'  => $row->sent_at ? Carbon::parse($row->sent_at)->toIso8601String() : null,
            'reminder_response' => $row->response,
            'reminder_error'    => $status === 'failed' ? $row->error : null,
        ];
    }
}
