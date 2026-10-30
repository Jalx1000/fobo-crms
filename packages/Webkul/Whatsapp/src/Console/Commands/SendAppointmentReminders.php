<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * Sends the appointment reminder (whatsapp.reminders.template) through the
 * active gateway to every patient with an appointment `days_before` days from
 * now. Idempotent: each appointment is reminded once (whatsapp_appointment_reminders).
 *
 * Meant to run daily from the scheduler.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'whatsapp:appointment-reminders
        {--date= : Target day (Y-m-d). Defaults to today + reminders.days_before}
        {--person= : Only remind this patient (person id) - for controlled tests}
        {--dry-run : List who would be reminded without sending}';

    protected $description = 'Envía recordatorios de cita por WhatsApp (gateway del CRM)';

    public function handle(ConversationResolver $resolver): int
    {
        // El broadcast global (sin --person) solo corre si está habilitado. Pero
        // --person es un test controlado a UN paciente: se permite aun con la
        // feature apagada, para poder probar el envío real sin encender el auto-envío
        // a todos los pacientes reales.
        if (! config('whatsapp.reminders.enabled')
            && ! $this->option('dry-run')
            && ! $this->option('person')) {
            $this->warn('Recordatorios deshabilitados (WHATSAPP_REMINDERS_ENABLED=false). Usá --person=<id> para un test controlado o --dry-run.');

            return self::SUCCESS;
        }

        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now()->addDays((int) config('whatsapp.reminders.days_before', 1));

        $appointments = $this->dueAppointments(
            $date->copy()->startOfDay(),
            $date->copy()->endOfDay(),
            $this->option('person') ? (int) $this->option('person') : null,
        );

        if ($appointments->isEmpty()) {
            $this->info("Sin citas pendientes de recordatorio para {$date->toDateString()}.");

            return self::SUCCESS;
        }

        $this->info("Citas para {$date->toDateString()}: {$appointments->count()}");

        $sent = 0;
        $skipped = 0;

        foreach ($appointments as $appt) {
            // A quien agendó (decisión del negocio): si la cita la sacó otra
            // persona por el formulario de WhatsApp -un padre para su hijo-, el
            // recordatorio le llega a ella, que además suele ser la única con
            // teléfono. Si no, al paciente, como siempre.
            $bookerId = (int) (json_decode((string) ($appt->additional ?? ''), true)['booked_by_person_id'] ?? 0);
            $recipientId = $bookerId ?: $appt->person_id;

            $person = $recipientId ? PersonProxy::modelClass()::find($recipientId) : null;
            $phone = $this->phoneOf($person);

            if (! $phone) {
                $this->line("  · omitida cita #{$appt->id} ({$appt->person_name}): sin teléfono");
                $skipped++;

                continue;
            }

            $variables = $this->buildVariables($appt);

            if ($this->option('dry-run')) {
                $this->line("  → [dry-run] {$appt->person_name} ({$phone}) - ".implode(' | ', $variables));

                continue;
            }

            $conversation = $resolver->findOrCreate($phone, $person->name ?? $appt->person_name);

            $msg = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'template',
                // Readable preview for the inbox; the real send uses the template.
                'body'            => $this->buildMessage($appt),
                'status'          => 'queued',
                'sender'          => 'system',
                'payload'         => [
                    'template'  => config('whatsapp.reminders.template_key', 'appointment_reminder'),
                    'variables' => $variables,
                ],
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            SendMessage::dispatch($msg->id, config('whatsapp.reminders.gateway'))
                ->onQueue(config('whatsapp.queue'));

            // Linked to the message so idempotency can tell a delivered reminder
            // from a failed one. updateOrInsert (activity_id is unique) lets a
            // retry re-point the row at the new message. Se guarda además el
            // external_id de SMD: es la clave estable que sobrevive a un re-sync,
            // así una cita recreada con otro activity_id no se recuerda de nuevo.
            DB::table('whatsapp_appointment_reminders')->updateOrInsert(
                ['activity_id' => $appt->id],
                [
                    'external_id'     => $appt->external_id,
                    'conversation_id' => $conversation->id,
                    'message_id'      => $msg->id,
                    'sent_at'         => now(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            );

            $this->line("  ✓ {$appt->person_name} ({$phone})".($bookerId ? " → a quien agendó: {$person->name}" : ''));
            $sent++;
        }

        $this->newLine();
        $this->info($this->option('dry-run') ? 'Dry-run terminado.' : "Enviados: {$sent} · Omitidos: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Patient appointments (type=meeting) in the window that were not reminded
     * yet, with their patient and first doctor.
     */
    protected function dueAppointments(Carbon $from, Carbon $to, ?int $personId = null)
    {
        $prefix = DB::getTablePrefix();

        return DB::table('activities')
            ->leftJoin('activity_participants', 'activities.id', '=', 'activity_participants.activity_id')
            ->leftJoin('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
            ->leftJoin('doctors', 'doctors.id', '=', 'doctor_activities.doctor_id')
            ->leftJoin('persons', 'persons.id', '=', 'activity_participants.person_id')
            // El external_id de SMD de la cita: la clave estable para la
            // idempotencia, la que no cambia aunque la cita se recree.
            ->leftJoin('smd_synced_events as ev', 'ev.activity_id', '=', 'activities.id')
            ->whereBetween('activities.schedule_from', [$from, $to])
            ->where('activities.type', 'meeting')
            ->where('activities.is_done', 0)
            ->when($personId, fn ($q) => $q->where('activity_participants.person_id', $personId))
            // "Already reminded" = a reminder row whose message did NOT fail.
            // A failed send (or a legacy row with no message) leaves the
            // appointment eligible again, so the next run retries it.
            //
            // Se compara por external_id Y por activity_id: el external_id cubre
            // las citas re-sincronizadas (mismo evento SMD, otro activity_id) y
            // el activity_id cubre las citas locales sin evento de SMD.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('whatsapp_appointment_reminders as r')
                    ->join('whatsapp_messages as m', 'm.id', '=', 'r.message_id')
                    ->where('m.status', '<>', 'failed')
                    ->where(function ($w) {
                        $w->whereColumn('r.activity_id', 'activities.id')
                            ->orWhere(function ($x) {
                                $x->whereColumn('r.external_id', 'ev.external_id')
                                    ->whereNotNull('ev.external_id');
                            });
                    });
            })
            ->groupBy('activities.id')
            ->orderBy('activities.schedule_from')
            ->get([
                'activities.id',
                'activities.schedule_from as start',
                'activity_participants.person_id',
                DB::raw('MAX('.$prefix.'ev.external_id) as external_id'),
                DB::raw('MAX('.$prefix.'activities.additional) as additional'),
                DB::raw('MAX('.$prefix.'persons.name) as person_name'),
                DB::raw('MAX('.$prefix.'doctors.name) as doctor_name'),
            ]);
    }

    /**
     * The approved template's variables for one appointment, IN ORDER:
     *   {{1}} = fecha y hora ("Lunes 20 de Julio a horas 17:00")
     *   {{2}} = doctor asignado
     * The gateway maps each to its provider slot (Kommo custom field / Cloud API
     * body parameter).
     *
     * @return array<int, string>
     */
    protected function buildVariables(object $appt): array
    {
        $start = Carbon::parse($appt->start);

        return [
            $this->longDate($start).' a horas '.$start->format('H:i'),
            $appt->doctor_name ?? '',
        ];
    }

    /**
     * Fill the template placeholders for one appointment.
     */
    protected function buildMessage(object $appt): string
    {
        $start = Carbon::parse($appt->start);

        return strtr(config('whatsapp.reminders.template'), [
            ':fecha'     => $this->longDate($start),
            ':hora'      => $start->format('H:i'),
            ':doctor'    => $appt->doctor_name ?? '',
            ':direccion' => config('whatsapp.clinic.address') ?? '',
            ':encuesta'  => config('whatsapp.clinic.survey_url') ?? '',
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
