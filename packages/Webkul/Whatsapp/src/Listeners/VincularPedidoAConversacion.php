<?php

namespace Webkul\Whatsapp\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Services\LeadOpener;

/**
 * Cuando se crea un pedido para una persona (el agente por la API, una persona en el
 * CRM o el Punto), sus conversaciones de WhatsApp pasan a apuntar a ese pedido, y el
 * pedido vacío que había abierto el CRM se borra.
 *
 * Sin el vínculo, el evento al agente seguía con `lead: null`, el agente no sabía que
 * el pedido ya existía y creaba otro (8285: dos pedidos a la misma hora).
 *
 * El vacío se BORRA y no se cancela: en Sensia no hay etapa «descartado», y mandarlo
 * a Cancelados lo contaría en el tablero como una venta perdida que nunca existió.
 * Solo se borra uno que nadie tocó (sin productos ni valor, abierto): un cliente que
 * compra dos veces conserva sus dos pedidos.
 *
 * Kohlberg hace lo mismo con DiscardEmptyWhatsappLead.
 */
class VincularPedidoAConversacion
{
    public function __construct(protected LeadOpener $opener) {}

    public function handle($lead): void
    {
        if (! $lead instanceof Model || ! $lead->person_id) {
            return;
        }

        try {
            $fantasmas = $this->opener->isUntouched($lead)
                ? collect()
                : LeadProxy::modelClass()::query()
                    ->where('person_id', $lead->person_id)
                    ->where('id', '!=', $lead->id)
                    ->where('title', 'like', LeadOpener::TITLE_PREFIX.'%')
                    ->get()
                    ->filter(fn ($otro) => $this->opener->isUntouched($otro));

            DB::table('whatsapp_conversations')
                ->where('person_id', $lead->person_id)
                ->update(['lead_id' => $lead->id]);

            foreach ($fantasmas as $fantasma) {
                $fantasma->delete();

                Log::info('WhatsApp: pedido vacío reemplazado', [
                    'borrado'         => $fantasma->id,
                    'reemplazado_por' => $lead->id,
                ]);
            }
        } catch (Throwable $e) {
            // Corre DESPUÉS de crear el pedido real: si esto falla, el pedido queda
            // creado igual. Un vacío de más es mucho mejor que perder el real.
            Log::warning('WhatsApp: no se pudo vincular el pedido a la conversación', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
