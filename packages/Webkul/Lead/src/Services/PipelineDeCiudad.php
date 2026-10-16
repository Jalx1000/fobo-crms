<?php

namespace Webkul\Lead\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\CiudadCliente;
use Webkul\Lead\Models\PipelineProxy;
use Webkul\Product\Support\ListaPrecios;

/**
 * El pipeline donde nace un pedido: el de la ciudad del cliente; sin ciudad,
 * «Sin ciudad».
 *
 * Antes nacía en el pipeline por defecto (Santa Cruz): el 14/10 había 178
 * pedidos sin ciudad mezclados en el tablero de Santa Cruz. Cuando el cliente
 * dice su ciudad, CiudadDesdeChat lo pasa al pipeline de esa ciudad.
 */
class PipelineDeCiudad
{
    public const SIN_CIUDAD = 'Sin ciudad';

    public function __construct(protected CiudadCliente $ciudades) {}

    public function deContacto(?int $personId)
    {
        return $this->para($personId ? $this->ciudades->de('persons', $personId) : null);
    }

    public function para(?string $ciudad)
    {
        $pipelines = PipelineProxy::modelClass()::all();

        $porNombre = fn (string $nombre) => $pipelines->first(
            fn ($p) => ListaPrecios::comparable((string) $p->name) === ListaPrecios::comparable($nombre)
        );

        // El pipeline es el de la zona: un cliente de El Alto va a La Paz.
        return ($ciudad ? $porNombre((string) CiudadCliente::zona($ciudad)) : null)
            ?? $porNombre(self::SIN_CIUDAD)
            ?? $pipelines->firstWhere('is_default', 1)
            ?? $pipelines->first();
    }

    /**
     * Con la ciudad ya conocida, el pedido que espera en «Sin ciudad» pasa al
     * pipeline de esa ciudad, en la misma etapa. Si ya está en el pipeline de
     * una ciudad (lo puso ahí un asesor), no se toca.
     */
    public function moverSiEspera(int $leadId, string $ciudad): bool
    {
        $lead = DB::table('leads')->find($leadId);
        $destino = $this->para($ciudad);
        $actual = $lead ? PipelineProxy::modelClass()::find($lead->lead_pipeline_id) : null;

        if (! $lead || ! $destino || ! $actual
            || ListaPrecios::comparable((string) $actual->name) !== ListaPrecios::comparable(self::SIN_CIUDAD)
            || ListaPrecios::comparable((string) $destino->name) === ListaPrecios::comparable(self::SIN_CIUDAD)) {
            return false;
        }

        $codigo = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code');

        $etapa = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $destino->id)->where('code', $codigo)->value('id')
            ?? DB::table('lead_pipeline_stages')->where('lead_pipeline_id', $destino->id)->orderBy('sort_order')->value('id');

        if (! $etapa) {
            return false;
        }

        DB::table('leads')->where('id', $leadId)->update([
            'lead_pipeline_id'       => $destino->id,
            'lead_pipeline_stage_id' => $etapa,
        ]);

        return true;
    }
}
