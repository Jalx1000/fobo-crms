<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * "No-show": si una paciente faltó a su cita, se le manda UNA sola vez el
 * mensaje de reprogramación, pasada la hora de su cita + `no_show.hours_after`
 * (2 h por defecto). Idempotente: un follow-up por cita
 * (whatsapp_no_show_followups). Pensado para correr seguido desde el scheduler.
 */
class SendNoShowFollowups extends Command
{
    protected $signature = 'whatsapp:no-show-followups
        {--person= : Solo esta paciente (person id) - para pruebas controladas}
        {--dry-run : Listar a quién se avisaría sin enviar}';

    protected $description = 'Envía el seguimiento de no-show (reprogramación) a +2 h de la cita, una sola vez';

    public function handle(ConversationResolver $resolver): int
    {
        if (! config('whatsapp.no_show.enabled')
            && ! $this->option('dry-run')
            && ! $this->option('person')) {
            $this->warn('No-show deshabilitado (WHATSAPP_NO_SHOW_ENABLED=false). Usá --person=<id> o --dry-run.');

            return self::SUCCESS;
        }

        $appointments = $this->dueNoShows(
            $this->option('person') ? (int) $this->option('person') : null
        );

        if ($appointments->isEmpty()) {
            $this->info('Sin citas en condición de no-show pendientes de seguimiento.');

            return self::SUCCESS;
        }

        $this->info("Citas no-show a seguir: {$appointments->count()}");

        $sent = 0;
        $skipped = 0;

        foreach ($appointments as $appt) {
            $bookerId = (int) (json_decode((string) ($appt->additional ?? ''), true)['booked_by_person_id'] ?? 0);
            $recipientId = $bookerId ?: $appt->person_id;

            $person = $recipientId ? PersonProxy::modelClass()::find($recipientId) : null;
            $phone = $this->phoneOf($person);

            if (! $phone) {
                $this->line("  · omitida cita #{$appt->id} ({$appt->person_name}): sin teléfono");
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  → [dry-run] {$appt->person_name} ({$phone}) - cita {$appt->start}");

                continue;
            }

            $conversation = $resolver->findOrCreate($phone, $person->name ?? $appt->person_name);

            $msg = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'template',
                'body'            => $this->buildMessage($appt),
                'status'          => 'queued',
                'sender'          => 'system',
                'payload'         => [
                    'template'  => config('whatsapp.no_show.template_key', 'no_show_followup'),
                    'variables' => $this->buildVariables($appt),
                ],
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            SendMessage::dispatch($msg->id, config('whatsapp.no_show.gateway'))
                ->onQueue(config('whatsapp.queue'));

            // Un follow-up por cita: la clave única (activity_id) hace idempotente
            // el comando aunque corra muchas veces en la ventana.
            DB::table('whatsapp_no_show_followups')->updateOrInsert(
                ['activity_id' => $appt->id],
                [
                    'conversation_id' => $conversation->id,
                    'message_id'      => $msg->id,
                    'sent_at'         => now(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            );

            // Marcar la cita como no-show (aditivo: solo si existe la columna).
            if (Schema::hasColumn('activities', 'status')) {
                DB::table('activities')->where('id', $appt->id)
                    ->update(['status' => \Webkul\Activity\Enums\AppointmentStatus::NO_SHOW]);
            }

            $this->line("  ✓ {$appt->person_name} ({$phone})");
            $sent++;
        }

        $this->newLine();
        $this->info($this->option('dry-run') ? 'Dry-run terminado.' : "Enviados: {$sent} · Omitidos: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Citas (type=meeting) cuya hora de fin + hours_after ya pasó, no completadas,
     * no canceladas, dentro de la ventana de lookback y sin seguimiento previo.
     */
    protected function dueNoShows(?int $personId = null)
    {
        $prefix = DB::getTablePrefix();

        $hoursAfter    = (float) config('whatsapp.no_show.hours_after', 2);
        $lookbackHours = (int) config('whatsapp.no_show.lookback_hours', 48);
        $cancelledStage = (int) config('smd.stage_map.cancelled');

        // Techo: citas cuyo fin quedó al menos `hours_after` en el pasado.
        $cutoff   = now()->subMinutes((int) round($hoursAfter * 60));
        // Piso: no perseguir citas más viejas que `lookback_hours`.
        $lookback = now()->subHours($lookbackHours);

        return DB::table('activities')
            ->leftJoin('activity_participants', 'activities.id', '=', 'activity_participants.activity_id')
            ->leftJoin('persons', 'persons.id', '=', 'activity_participants.person_id')
            ->where('activities.type', 'meeting')
            ->where('activities.is_done', 0)
            ->whereNotNull('activities.schedule_to')
            ->where('activities.schedule_to', '<=', $cutoff)
            ->where('activities.schedule_from', '>=', $lookback)
            ->when($personId, fn ($q) => $q->where('activity_participants.person_id', $personId))
            // Excluir citas canceladas (status cerrado o stage cancelado). El
            // no-show NO se excluye: es justamente a quien queremos escribir.
            ->whereNotExists(function ($q) use ($cancelledStage) {
                $q->select(DB::raw(1))
                    ->from('lead_activities')
                    ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
                    ->whereColumn('lead_activities.activity_id', 'activities.id')
                    ->where(function ($w) use ($cancelledStage) {
                        $w->where('leads.status', 0)
                            ->orWhere('leads.lead_pipeline_stage_id', $cancelledStage);
                    });
            })
            // Sin seguimiento de no-show previo (una sola vez).
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('whatsapp_no_show_followups as f')
                    ->whereColumn('f.activity_id', 'activities.id');
            })
            ->groupBy('activities.id')
            ->orderBy('activities.schedule_from')
            ->get([
                'activities.id',
                'activities.schedule_from as start',
                'activity_participants.person_id',
                DB::raw('MAX('.$prefix.'activities.additional) as additional'),
                DB::raw('MAX('.$prefix.'persons.name) as person_name'),
            ]);
    }

    /**
     * Variables del template aprobado, EN ORDEN:
     *   {{1}} = nombre de la paciente
     *   {{2}} = fecha de la cita faltada ("Lunes 20 de Julio")
     *
     * @return array<int, string>
     */
    protected function buildVariables(object $appt): array
    {
        return [
            $appt->person_name ?? '',
            $this->longDate(Carbon::parse($appt->start)),
        ];
    }

    protected function buildMessage(object $appt): string
    {
        return strtr(config('whatsapp.no_show.template'), [
            ':nombre' => $appt->person_name ?? '',
            ':fecha'  => $this->longDate(Carbon::parse($appt->start)),
        ]);
    }

    protected function longDate(Carbon $d): string
    {
        $days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        $months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        return $days[$d->dayOfWeek].' '.$d->day.' de '.$months[$d->month - 1];
    }

    protected function phoneOf($person): ?string
    {
        if (! $person || ! is_array($person->contact_numbers)) {
            return null;
        }

        $first = $person->contact_numbers[0] ?? null;

        return is_array($first) ? ($first['value'] ?? null) : $first;
    }
}
