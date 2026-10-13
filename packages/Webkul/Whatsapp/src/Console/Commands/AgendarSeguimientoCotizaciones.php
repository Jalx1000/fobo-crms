<?php

namespace Webkul\Whatsapp\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Jobs\SeguimientoCotizacion;

/**
 * Agenda el seguimiento de los pedidos que ya estaban en COTIZACION ENVIADA
 * cuando se activó. Los nuevos se agendan solos al enviar la cotización.
 */
class AgendarSeguimientoCotizaciones extends Command
{
    protected $signature = 'pedidos:agendar-seguimiento';

    protected $description = 'Agenda recordatorio y SIN INTERÉS de los pedidos en COTIZACION ENVIADA.';

    public function handle(): int
    {
        $etapas = DB::table('lead_pipeline_stages')->where('code', 'cotizacion-enviada')->pluck('id');

        $agendados = 0;

        foreach (DB::table('leads')->whereIn('lead_pipeline_stage_id', $etapas)->get(['id', 'updated_at']) as $lead) {
            $quoteId = DB::table('lead_quotes')->where('lead_id', $lead->id)->max('quote_id');

            if (! $quoteId) {
                continue;
            }

            // Cuándo se envió: el último mensaje con esa cotización; si no hay, cuándo se movió el pedido.
            $enviada = DB::table('whatsapp_messages')->where('payload->quote_id', $quoteId)->max('created_at') ?? $lead->updated_at;

            SeguimientoCotizacion::agendar((int) $lead->id, (int) $quoteId, Carbon::parse($enviada));

            $agendados++;
        }

        $this->info("Pedidos con seguimiento agendado: {$agendados}");

        return self::SUCCESS;
    }
}
