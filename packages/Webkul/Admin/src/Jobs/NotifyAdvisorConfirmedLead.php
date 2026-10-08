<?php

namespace Webkul\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\Admin\Mail\ConfirmedLeadForAdvisor;
use Webkul\Lead\Models\LeadProxy;

/**
 * Manda el aviso de pedido confirmado al asesor dueño del pedido.
 *
 * Recibe el id y no el modelo: SerializesModels guardaría el lead completo en
 * la cola y, cuando el job corra, podría estar desactualizado. Con el id se lee
 * el estado del momento del envío.
 */
class NotifyAdvisorConfirmedLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(public int $leadId) {}

    public function handle(): void
    {
        $lead = LeadProxy::modelClass()::with(['person', 'products', 'stage', 'pipeline', 'user'])->find($this->leadId);

        if (! $lead) {
            return;
        }

        $destinatarios = $this->destinatarios($lead);

        if (empty($destinatarios)) {
            Log::warning('Pedido confirmado: nadie a quien avisar', ['lead_id' => $lead->id]);

            return;
        }

        try {
            Mail::to($destinatarios)->send(new ConfirmedLeadForAdvisor($lead));

            Log::info('Pedido confirmado: aviso enviado', [
                'lead_id' => $lead->id,
                'para'    => $destinatarios,
            ]);
        } catch (Throwable $e) {
            Log::error('Pedido confirmado: falló el aviso', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);

            throw $e; // Que reintente.
        }
    }

    /**
     * El asesor dueño del pedido, más las copias fijas de configuración.
     *
     * @return array<int, string>
     */
    private function destinatarios($lead): array
    {
        $correos = [];

        if ($email = optional($lead->user)->email) {
            $correos[] = $email;
        }

        foreach ((array) config('leads.confirmed_order_copies', []) as $copia) {
            if (filter_var($copia, FILTER_VALIDATE_EMAIL)) {
                $correos[] = $copia;
            }
        }

        return array_values(array_unique($correos));
    }
}
