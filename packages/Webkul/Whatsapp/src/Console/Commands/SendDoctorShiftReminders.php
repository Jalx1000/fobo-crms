<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;
use Webkul\Whatsapp\Services\PhoneNumber;

/**
 * Avisa a cada doctor por WhatsApp al inicio de su PRIMER turno del día, con un
 * template UTILITY aprobado ({{1}}=nombre, {{2}}=hora). Idempotente: uno por
 * doctor por día (whatsapp_doctor_shift_reminders).
 *
 * Pensado para correr seguido desde el scheduler: cada doctor recibe el aviso
 * cuando su primer turno arrancó, y no de nuevo el mismo día.
 */
class SendDoctorShiftReminders extends Command
{
    protected $signature = 'whatsapp:doctor-shift-reminders
        {--date= : Día objetivo (Y-m-d). Default: hoy}
        {--doctor= : Solo este doctor (id) - para tests controlados}
        {--dry-run : Lista a quién avisaría sin enviar}';

    protected $description = 'Avisa a los doctores por WhatsApp al inicio de su turno';

    public function handle(ConversationResolver $resolver): int
    {
        // El broadcast (sin --doctor) solo corre si está habilitado. --doctor es
        // un test controlado a UNO: se permite aun con la feature apagada.
        if (! config('whatsapp.doctor_shift.enabled')
            && ! $this->option('dry-run')
            && ! $this->option('doctor')) {
            $this->warn('Avisos a doctores deshabilitados (WHATSAPP_DOCTOR_SHIFT_ENABLED=false). Usá --doctor=<id> para un test o --dry-run.');

            return self::SUCCESS;
        }

        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now();

        $doctors = $this->dueDoctors($date, $this->option('doctor') ? (int) $this->option('doctor') : null);

        if ($doctors->isEmpty()) {
            $this->info("Sin doctores por avisar para {$date->toDateString()}.");

            return self::SUCCESS;
        }

        $this->info("Doctores para {$date->toDateString()}: {$doctors->count()}");

        $sent = 0;
        $skipped = 0;

        foreach ($doctors as $doctor) {
            $phone = PhoneNumber::withCountryCode($doctor->phone, config('whatsapp.default_country_code'));

            if (! $phone) {
                $this->line("  · omitido {$doctor->name}: sin teléfono válido");
                $skipped++;

                continue;
            }

            // Meta rechaza parámetros de template con saltos de línea / tabs /
            // 4+ espacios (error 132018). Los nombres de doctores vienen con
            // \n al final, así que se sanean.
            $nombre = trim(preg_replace('/\s+/', ' ', (string) $doctor->name));
            $hora = Carbon::parse($doctor->first_start)->format('H:i');
            $variables = [$nombre, $hora];

            if ($this->option('dry-run')) {
                $this->line("  → [dry-run] {$doctor->name} ({$phone}) - ".implode(' | ', $variables));

                continue;
            }

            $conversation = $resolver->findOrCreate($phone, $doctor->name);

            $msg = MessageProxy::modelClass()::create([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'template',
                'body'            => strtr(config('whatsapp.doctor_shift.template'), [
                    ':nombre' => $nombre,
                    ':hora'   => $hora,
                ]),
                'status'          => 'queued',
                'sender'          => 'system',
                'payload'         => [
                    'template'  => config('whatsapp.doctor_shift.template_key', 'doctor_shift_reminder'),
                    'variables' => $variables,
                ],
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            SendMessage::dispatch($msg->id, config('whatsapp.doctor_shift.gateway'))
                ->onQueue(config('whatsapp.queue'));

            // Idempotencia: uno por doctor por día. updateOrInsert por (doctor, date)
            // permite que un reintento (tras un envío fallido) re-apunte la fila.
            DB::table('whatsapp_doctor_shift_reminders')->updateOrInsert(
                ['doctor_id' => $doctor->id, 'date' => $date->toDateString()],
                [
                    'conversation_id' => $conversation->id,
                    'message_id'      => $msg->id,
                    'sent_at'         => now(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            );

            $this->line("  ✓ {$doctor->name} ({$phone}) - desde las {$hora}");
            $sent++;
        }

        $this->newLine();
        $this->info($this->option('dry-run') ? 'Dry-run terminado.' : "Enviados: {$sent} · Omitidos: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Doctores activos, con teléfono, cuyo PRIMER turno del día ya empezó (si el
     * día es hoy) y que todavía no recibieron el aviso hoy.
     */
    protected function dueDoctors(Carbon $date, ?int $doctorId)
    {
        $prefix = DB::getTablePrefix();
        $isToday = $date->isSameDay(now());

        return DB::table('doctor_shifts as s')
            ->join('doctors as d', 'd.id', '=', 's.doctor_id')
            ->where('s.date', $date->toDateString())
            ->where('d.is_active', 1)
            ->whereNotNull('d.phone')
            ->where('d.phone', '<>', '')
            ->when($doctorId, fn ($q) => $q->where('d.id', $doctorId))
            // Un envío que no falló cuenta como ya avisado ese día.
            ->whereNotExists(function ($q) use ($date) {
                $q->select(DB::raw(1))
                    ->from('whatsapp_doctor_shift_reminders as r')
                    ->join('whatsapp_messages as m', 'm.id', '=', 'r.message_id')
                    ->where('m.status', '<>', 'failed')
                    ->whereColumn('r.doctor_id', 'd.id')
                    ->where('r.date', $date->toDateString());
            })
            ->groupBy('d.id', 'd.name', 'd.phone')
            // Para hoy, solo si el primer turno ya arrancó. Para otros días
            // (tests/--date), no se filtra por hora.
            ->when($isToday, fn ($q) => $q->havingRaw('MIN('.$prefix.'s.start_time) <= ?', [now()->format('H:i:s')]))
            ->get([
                'd.id',
                'd.name',
                'd.phone',
                DB::raw('MIN('.$prefix.'s.start_time) as first_start'),
            ]);
    }
}
