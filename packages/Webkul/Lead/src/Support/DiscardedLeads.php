<?php

namespace Webkul\Lead\Support;

use Illuminate\Support\Facades\DB;

/**
 * Los pedidos en "Descartado" no cuentan como venta en ningún reporte.
 *
 * Descartado es la etapa donde van los duplicados: el pedido fantasma que quedó
 * al lado del real, o la copia que el agente registró dos veces. Mientras los
 * reportes de ingresos no miraran la etapa, un duplicado descartado seguía
 * sumando su monto y el ranking de productos contaba la misma venta dos veces.
 *
 * Sólo se excluye "descartado". "Pedidos entregados" y "Pedidos cancelados"
 * también son etapas cerradas, pero representan hechos reales y siguen contando
 * donde corresponda.
 */
final class DiscardedLeads
{
    public const STAGE_CODE = 'descartado';

    /**
     * Ids de la etapa Descartado en todos los pipelines (uno por ciudad).
     *
     * Devuelve [0] cuando no existe la etapa, para que un whereNotIn no quede
     * vacío —MySQL rechaza IN ()— y la query siga siendo válida.
     *
     * @return array<int, int>
     */
    public static function stageIds(): array
    {
        $ids = DB::table('lead_pipeline_stages')
            ->where('code', self::STAGE_CODE)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $ids ?: [0];
    }
}
