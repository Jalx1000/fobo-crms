<?php

namespace Webkul\Whatsapp\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Admin\Services\BookingOptions;
use Webkul\Admin\Services\PatientAppointments;
use Webkul\Whatsapp\Jobs\Concerns\RepliesToConversation;

/**
 * Mueve la cita al horario pedido en el formulario "Reprogramar cita", tras
 * revalidar en vivo con SMD que siga libre, y avisa por el chat.
 */
class RescheduleFlowAppointment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RepliesToConversation, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $activityId,
        public int $conversationId,
        public string $date,
        public string $time,
        public int $duration,
    ) {
        $this->requestedAt = time();
    }

    public function handle(PatientAppointments $appointments, BookingOptions $booking): void
    {
        $doctorId = (int) DB::table('doctor_activities')->where('activity_id', $this->activityId)->value('doctor_id');
        $from = Carbon::parse($this->date.' '.$this->time, config('app.timezone'));
        $when = ucfirst($from->copy()->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm'));
        $doctor = trim((string) DB::table('doctors')->where('id', $doctorId)->value('name'));

        try {
            // El catálogo puede tener minutos de atraso: se confirma en vivo.
            if (! $doctorId || ! $booking->isLiveFree($doctorId, $this->date, $this->time)) {
                $this->replyAfterAgent($this->conversationId, "No pudimos cambiar tu cita al {$when}: ese horario ya no está disponible. "
                    .'Tu cita sigue en el horario anterior. Escribinos y te ayudamos a elegir otro. 🙏');

                return;
            }

            $result = $appointments->reschedule($this->activityId, $from, $this->duration);
        } catch (Throwable $e) {
            Log::error('[WhatsAppFlow] Error reprogramando la cita del formulario', ['activity_id' => $this->activityId, 'error' => $e->getMessage()]);
            $this->replyAfterAgent($this->conversationId, 'Tuvimos un problema al cambiar tu cita. Escribinos y te ayudamos. 🙏');

            return;
        }

        if (! $result['synced']) {
            Log::warning('[WhatsAppFlow] Cita reprogramada en el CRM pero no en SMD', ['activity_id' => $this->activityId, 'motivo' => $result['reason']]);
        }

        $this->replyAfterAgent($this->conversationId, "✅ Cita reprogramada\nDoctor/a: {$doctor}\nNueva fecha: {$when}");
    }
}
