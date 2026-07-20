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
        {--person= : Only remind this patient (person id) — for controlled tests}
        {--dry-run : List who would be reminded without sending}';

    protected $description = 'Envía recordatorios de cita por WhatsApp (gateway del CRM)';

    public function handle(ConversationResolver $resolver): int
    {
        if (! config('whatsapp.reminders.enabled') && ! $this->option('dry-run')) {
            $this->warn('Recordatorios deshabilitados (WHATSAPP_REMINDERS_ENABLED=false). Nada que hacer.');

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
            $person = $appt->person_id ? PersonProxy::modelClass()::find($appt->person_id) : null;
            $phone = $this->phoneOf($person);

            if (! $phone) {
                $this->line("  · omitida cita #{$appt->id} ({$appt->person_name}): sin teléfono");
                $skipped++;

                continue;
            }

            $message = $this->buildMessage($appt);

            if ($this->option('dry-run')) {
                $this->line("  → [dry-run] {$appt->person_name} ({$phone}) — ".Carbon::parse($appt->start)->format('H:i'));

                continue;
            }

            $conversation = $resolver->findOrCreate($phone, $appt->person_name);

            $msg = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'text',
                'body'            => $message,
                'status'          => 'queued',
                'sender'          => 'system',
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            SendMessage::dispatch($msg->id)->onQueue(config('whatsapp.queue'));

            DB::table('whatsapp_appointment_reminders')->insert([
                'activity_id'     => $appt->id,
                'conversation_id' => $conversation->id,
                'sent_at'         => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $this->line("  ✓ {$appt->person_name} ({$phone})");
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
        return DB::table('activities')
            ->leftJoin('activity_participants', 'activities.id', '=', 'activity_participants.activity_id')
            ->leftJoin('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
            ->leftJoin('doctors', 'doctors.id', '=', 'doctor_activities.doctor_id')
            ->leftJoin('persons', 'persons.id', '=', 'activity_participants.person_id')
            ->whereBetween('activities.schedule_from', [$from, $to])
            ->where('activities.type', 'meeting')
            ->where('activities.is_done', 0)
            ->when($personId, fn ($q) => $q->where('activity_participants.person_id', $personId))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('whatsapp_appointment_reminders as r')
                    ->whereColumn('r.activity_id', 'activities.id');
            })
            ->groupBy('activities.id')
            ->orderBy('activities.schedule_from')
            ->get([
                'activities.id',
                'activities.schedule_from as start',
                'activity_participants.person_id',
                DB::raw('MAX('.DB::getTablePrefix().'persons.name) as person_name'),
                DB::raw('MAX('.DB::getTablePrefix().'doctors.name) as doctor_name'),
            ]);
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
