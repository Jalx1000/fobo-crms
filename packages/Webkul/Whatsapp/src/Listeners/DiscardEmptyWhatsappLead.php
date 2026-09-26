<?php

namespace Webkul\Whatsapp\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Services\LeadOpener;

/**
 * Descarta el pedido vacío que el CRM abrió solo, cuando llega el pedido real.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * El CRM abre un pedido en cuanto alguien escribe por WhatsApp, para que esa
 * persona aparezca en algún pipeline. Minutos después el agente registra la
 * venta con POST /api/v1/leads y crea OTRO, porque esa API es la genérica de
 * Krayin y no sabe que ya hay uno esperando. Medido el 2026-09-22: 81 pedidos
 * fantasma, cada uno además en el pipeline y con el asesor equivocados —el
 * vacío hereda "Sin ciudad", el real tiene la ciudad de verdad.
 *
 * POR QUÉ UN LISTENER Y NO REUSAR EL PEDIDO
 *
 * Reusarlo exigiría cambiar el controlador de la API REST, que vive en
 * vendor/krayin/rest-api: el próximo composer update lo pisaría sin avisar.
 * El listener cuelga de un evento que ese controlador ya dispara, así que
 * funciona para TODOS los caminos —API, panel, importación— y no le pide nada
 * al equipo del agente.
 *
 * LOS MULTIPEDIDOS SIGUEN INTACTOS
 *
 * Sólo se descarta un pedido que nadie tocó. El primer pedido real consume el
 * fantasma; el segundo ya no encuentra ninguno y no pasa nada. Un cliente que
 * compra dos veces conserva sus dos pedidos.
 */
class DiscardEmptyWhatsappLead
{
    public function __construct(protected LeadOpener $opener) {}

    public function handle($lead): void
    {
        if (! $lead instanceof Model || ! $lead->person_id) {
            return;
        }

        try {
            // El recién creado no puede descartarse a sí mismo.
            if ($this->opener->isUntouched($lead)) {
                return;
            }

            $fantasmas = LeadProxy::modelClass()::query()
                ->where('person_id', $lead->person_id)
                ->where('id', '!=', $lead->id)
                ->where('title', 'like', LeadOpener::TITLE_PREFIX.'%')
                ->get()
                ->filter(fn ($otro) => $this->opener->isUntouched($otro));

            foreach ($fantasmas as $fantasma) {
                $this->descartar($fantasma, $lead->id);
            }
        } catch (Throwable $e) {
            /*
             * Se traga la excepción a propósito: esto corre DESPUÉS de crear el
             * pedido real. Si la limpieza falla, el pedido tiene que quedar
             * creado igual — perderlo sería mucho peor que un fantasma de más,
             * que además el comando de limpieza puede barrer después.
             */
            Log::warning('WhatsApp: no se pudo descartar el pedido vacío', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function descartar(Model $fantasma, int $reemplazadoPor): void
    {
        $etapa = \Illuminate\Support\Facades\DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $fantasma->lead_pipeline_id)
            ->where('code', 'descartado')
            ->value('id');

        if (! $etapa) {
            return;
        }

        // Sin tocar updated_at: no es una edición del pedido, es el cierre de
        // algo que nunca tuvo contenido.
        $fantasma->timestamps = false;

        $fantasma->forceFill([
            'lead_pipeline_stage_id' => $etapa,
            'title'                  => $fantasma->title.' (reemplazado por #'.$reemplazadoPor.')',
        ])->save();

        Log::info('WhatsApp: pedido vacío descartado', [
            'descartado'      => $fantasma->id,
            'reemplazado_por' => $reemplazadoPor,
        ]);
    }
}
