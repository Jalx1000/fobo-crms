<?php

namespace Webkul\Admin\Listeners;

use Illuminate\Support\Facades\DB;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Cotización enviada por WhatsApp o Messenger → actividad en la oportunidad y el contacto:
 * «Cotización #2198 enviada por WhatsApp a +591… (Fabio Sandoval)».
 *
 * Así el historial cuenta que ya se mandó, quién y a qué número, aunque el
 * asesor esté mirando otro canal (en Kommo, lo que sale por Cloud API no se ve).
 */
class CotizacionEnviada
{
    public function __construct(protected ActivityRepository $activities) {}

    public function handle($quote): void
    {
        if (! $quote?->id) {
            return;
        }

        $mensaje = MessageProxy::modelClass()::query()
            ->where('payload->quote_id', $quote->id)
            ->with('conversation')
            ->latest('id')
            ->first();

        $destino = $mensaje?->conversation?->wa_phone ?: $mensaje?->conversation?->wa_name;

        // WhatsApp, WhatsApp (Kommo) o Messenger: el canal por el que salió.
        $canal = $mensaje?->conversation
            ? app(GatewayManager::class)->label((string) $mensaje->conversation->gateway)
            : 'WhatsApp';

        $usuario = auth()->guard('user')->user();

        $activity = $this->activities->create([
            'type'    => 'system',
            'title'   => trans('admin::app.quotes.index.whatsapp.activity', [
                'id'      => $quote->id,
                'channel' => $canal,
                'to'      => $destino ?: trans('admin::app.quotes.index.whatsapp.param-customer'),
                'user'    => $usuario?->name ?? '—',
            ]),
            'is_done' => 1,
            'user_id' => $usuario?->id,
        ]);

        // Las oportunidades se leen del pivot: $quote->leads revienta con el
        // pivot de Lead (ver CotizacionNueva::oportunidad()).
        $leadIds = DB::table('lead_quotes')->where('quote_id', $quote->id)->pluck('lead_id');

        foreach (LeadProxy::modelClass()::whereIn('id', $leadIds)->get() as $lead) {
            $lead->activities()->attach($activity->id);
        }

        if ($quote->person_id && $person = PersonProxy::modelClass()::find($quote->person_id)) {
            $person->activities()->attach($activity->id);
        }
    }
}
