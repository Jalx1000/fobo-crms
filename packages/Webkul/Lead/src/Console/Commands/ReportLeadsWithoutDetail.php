<?php

namespace Webkul\Lead\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lista las ventas cerradas que no dicen qué se vendió.
 *
 * La validación de LeadForm corta los casos nuevos que vienen por la API, y el
 * amount derivado en el modelo Product corta los montos en cero. Pero ninguna
 * de las dos ve lo que ya está cargado, ni lo que un asesor cierra a mano desde
 * el formulario del admin —que queda fuera de la regla a propósito—.
 *
 * Esto es lo que hace que el agujero se note en una semana en vez de en seis
 * meses: 27 de 182 pedidos confirmados o entregados desde el 01/08/2026 no
 * tienen una sola línea de producto, y nadie se había enterado.
 */
class ReportLeadsWithoutDetail extends Command
{
    /**
     * Etapas donde el pedido ya se ganó: si llegó acá sin detalle, se vendió
     * algo que no quedó registrado en ningún lado.
     */
    private const ETAPAS_GANADAS = ['cliente-confirmado', 'pedidos-entregados'];

    protected $signature = 'leads:sin-detalle
        {--desde=2026-08-01 : No mirar pedidos anteriores a esta fecha}
        {--todas : Incluir también las etapas abiertas, no sólo las ganadas}';

    protected $description = 'Lista los pedidos cerrados como venta que no tienen ninguna línea de producto';

    public function handle(): int
    {
        $desde = (string) $this->option('desde');

        $consulta = DB::table('leads')
            ->join('lead_pipeline_stages as s', 's.id', '=', 'leads.lead_pipeline_stage_id')
            ->leftJoin('users as u', 'u.id', '=', 'leads.user_id')
            ->where('leads.created_at', '>=', $desde)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('lead_products')
                ->whereColumn('lead_products.lead_id', 'leads.id'))
            ->orderBy('leads.id');

        if (! $this->option('todas')) {
            $consulta->whereIn('s.code', self::ETAPAS_GANADAS);
        }

        $sinDetalle = $consulta->get([
            'leads.id', 'leads.title', 'leads.lead_value', 'leads.created_at',
            's.name as etapa', 'u.name as asesor',
        ]);

        $total = DB::table('leads')
            ->join('lead_pipeline_stages as s', 's.id', '=', 'leads.lead_pipeline_stage_id')
            ->where('leads.created_at', '>=', $desde)
            ->when(! $this->option('todas'), fn ($q) => $q->whereIn('s.code', self::ETAPAS_GANADAS))
            ->count();

        if ($sinDetalle->isEmpty()) {
            $this->info('Sin pendientes: los '.$total.' pedidos desde '.$desde.' tienen detalle.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Pedido', 'Valor', 'Etapa', 'Asesor', 'Creado'],
            $sinDetalle->map(fn ($l) => [
                $l->id,
                mb_strimwidth((string) $l->title, 0, 34, '…'),
                'Bs '.number_format((float) $l->lead_value, 0),
                $l->etapa,
                $l->asesor ?? '—',
                substr((string) $l->created_at, 0, 10),
            ])->all()
        );

        $porcentaje = $total > 0 ? round($sinDetalle->count() * 100 / $total) : 0;

        $this->newLine();
        $this->warn($sinDetalle->count().' de '.$total.' ('.$porcentaje.'%) sin una sola línea de producto.');
        $this->line('Esas ventas no aparecen en el ranking de productos: el reporte filtra con SUM(amount) > 0.');

        /*
         * Devuelve FAILURE a propósito para que sirva como chequeo en un cron o
         * en CI sin tener que parsear la salida.
         */
        return self::FAILURE;
    }
}
