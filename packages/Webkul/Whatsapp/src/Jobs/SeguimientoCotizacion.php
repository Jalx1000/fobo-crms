<?php

namespace Webkul\Whatsapp\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Webkul\Lead\Services\EtapaPedido;

/**
 * Seguimiento de una cotización enviada que el cliente no contesta (13/10).
 *
 * Al enviarla se agendan dos pasos:
 *   - RECORDATORIO (a los `recordatorio_dias`): correo al asesor del pedido
 *     para que llame o escriba.
 *   - SIN INTERÉS (a los `sin_interes_dias`): el pedido pasa a SIN INTERÉS.
 *
 * Cada paso corre solo si el pedido sigue en COTIZACION ENVIADA y el cliente
 * no escribió desde que se envió: si contestó, o el asesor ya lo movió, no
 * hace nada. Sin programador de tareas en el servidor, va como job demorado
 * en la cola whatsapp, igual que el cierre de derivaciones.
 */
class SeguimientoCotizacion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const RECORDATORIO = 'recordatorio';

    public const SIN_INTERES = 'sin_interes';

    public function __construct(
        public int $leadId,
        public int $quoteId,
        public string $enviadaAt,
        public string $paso,
    ) {}

    public static function habilitado(): bool
    {
        return (bool) config('cotizacion.seguimiento.enabled', true);
    }

    /**
     * Agenda los dos pasos para un pedido cuya cotización se envió en `$enviada`.
     */
    public static function agendar(int $leadId, int $quoteId, Carbon $enviada): void
    {
        if (! static::habilitado()) {
            return;
        }

        foreach ([
            self::RECORDATORIO => (int) config('cotizacion.seguimiento.recordatorio_dias', 3),
            self::SIN_INTERES  => (int) config('cotizacion.seguimiento.sin_interes_dias', 7),
        ] as $paso => $dias) {
            if ($dias <= 0) {
                continue;
            }

            $cuando = $enviada->copy()->addDays($dias);

            if (! AvisarDerivacionSinRespuesta::enHorario($cuando)) {
                $cuando = AvisarDerivacionSinRespuesta::proximaApertura($cuando);
            }

            static::dispatch($leadId, $quoteId, $enviada->toIso8601String(), $paso)
                ->onQueue(config('whatsapp.queue'))
                ->delay($cuando->isPast() ? now() : $cuando);
        }
    }

    public function handle(EtapaPedido $etapas): void
    {
        if (! static::habilitado()) {
            return;
        }

        $lead = DB::table('leads')->find($this->leadId);

        $codigo = $lead ? DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code') : null;

        // El asesor ya lo movió (o lo cerró): no se toca.
        if ($codigo !== 'cotizacion-enviada') {
            return;
        }

        // El cliente contestó después del envío: hay conversación, no seguimiento.
        if ($this->contesto($lead)) {
            return;
        }

        if ($this->paso === self::SIN_INTERES) {
            $etapas->mover($this->leadId, 'sin-inters', ['cotizacion-enviada']);

            return;
        }

        $this->recordar($lead);
    }

    protected function contesto(object $lead): bool
    {
        $conversaciones = DB::table('whatsapp_conversations')
            ->where('lead_id', $lead->id)
            ->when($lead->person_id, fn ($q) => $q->orWhere('person_id', $lead->person_id))
            ->pluck('id');

        return DB::table('whatsapp_messages')
            ->whereIn('conversation_id', $conversaciones)
            ->where('direction', 'inbound')
            ->where('created_at', '>', Carbon::parse($this->enviadaAt))
            ->exists();
    }

    protected function recordar(object $lead): void
    {
        $asesor = $lead->user_id ? DB::table('users')->where('id', $lead->user_id)->first(['name', 'email']) : null;

        if (! $asesor?->email) {
            return;
        }

        $cliente = $lead->person_id ? DB::table('persons')->where('id', $lead->person_id)->value('name') : null;

        $dias = (int) Carbon::parse($this->enviadaAt)->diffInDays(now());

        $url = route('admin.leads.view', $lead->id);

        $sinInteres = (int) config('cotizacion.seguimiento.sin_interes_dias', 7);

        $html = '<p>Hola '.e($asesor->name).',</p>'
            .'<p>La cotización <b>#'.$this->quoteId.'</b> a <b>'.e($cliente ?: 'el cliente').'</b> se envió hace '.$dias.' días y el cliente no contestó.</p>'
            .'<p>Escribile o llamalo. Si no responde, el pedido pasa solo a SIN INTERÉS a los '.$sinInteres.' días del envío.</p>'
            .'<p><a href="'.e($url).'">Ver el pedido #'.$lead->id.'</a></p>';

        try {
            Mail::html($html, fn ($m) => $m->to($asesor->email)
                ->subject('Cotización #'.$this->quoteId.' sin respuesta hace '.$dias.' días'));
        } catch (\Throwable $e) {
            Log::error('Seguimiento de cotización: no se pudo enviar el recordatorio.', [
                'lead'  => $lead->id,
                'quote' => $this->quoteId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
