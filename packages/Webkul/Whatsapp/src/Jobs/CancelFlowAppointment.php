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
use Webkul\Admin\Services\PatientAppointments;
use Webkul\Whatsapp\Jobs\Concerns\RepliesToConversation;

/**
 * Cancela la cita pedida por el formulario "Cancelar cita" y avisa por el chat.
 */
class CancelFlowAppointment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RepliesToConversation, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $activityId, public int $conversationId, public ?string $reason = null)
    {
        $this->requestedAt = time();
    }

    public function handle(PatientAppointments $appointments): void
    {
        $activity = DB::table('activities')->find($this->activityId);
        $when = $activity ? ucfirst(Carbon::parse($activity->schedule_from)->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm')) : '';

        try {
            $result = $appointments->cancel($this->activityId, $this->reason);
        } catch (Throwable $e) {
            Log::error('[WhatsAppFlow] Error cancelando la cita del formulario', ['activity_id' => $this->activityId, 'error' => $e->getMessage()]);
            $this->replyAfterAgent($this->conversationId, 'Tuvimos un problema al cancelar tu cita. Escribinos y te ayudamos. 🙏');

            return;
        }

        if (! $result['synced']) {
            // Cancelada en el CRM; si SMD no la tomó, que recepción lo vea en el log.
            Log::warning('[WhatsAppFlow] Cita cancelada en el CRM pero no en SMD', ['activity_id' => $this->activityId, 'motivo' => $result['reason']]);
        }

        $this->replyAfterAgent($this->conversationId, "✅ Cita cancelada\nFecha: {$when}\nSi querés agendar otra, escribinos. 🦷");
    }
}
