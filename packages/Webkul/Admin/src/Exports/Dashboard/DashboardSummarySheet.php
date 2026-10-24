<?php

namespace Webkul\Admin\Exports\Dashboard;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Webkul\Admin\Helpers\Dashboard;

/**
 * "Resumen" sheet of the dashboard export: the KPIs exactly as the dashboard
 * cards show them, for the active city/date filter.
 */
class DashboardSummarySheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * Create a new sheet instance.
     */
    public function __construct(protected Dashboard $dashboard) {}

    /**
     * Sheet title.
     */
    public function title(): string
    {
        return 'Resumen';
    }

    /**
     * Column headings.
     */
    public function headings(): array
    {
        return ['Métrica', 'Valor'];
    }

    /**
     * KPI rows.
     */
    public function array(): array
    {
        $overall = $this->dashboard->getOverAllStats();
        $revenue = $this->dashboard->getRevenueStats();

        return [
            ['Rango de fechas', $this->dashboard->getDateRange()],
            ['Total de leads', $overall['total_leads']['current'] ?? 0],
            ['Valor promedio de lead', $overall['average_lead_value']['current'] ?? 0],
            ['Promedio de leads por día', $overall['average_leads_per_day']['current'] ?? 0],
            ['Inmuebles consultados', $overall['total_services']['current'] ?? 0],
            ['Inmuebles cerrados', $overall['total_products_sold']['current'] ?? 0],
            ['Cotizaciones', $overall['total_quotations']['current'] ?? 0],
            ['Total de contactos', $overall['total_persons']['current'] ?? 0],
            ['Total de organizaciones', $overall['total_organizations']['current'] ?? 0],
            ['Valor de pedidos ganados', $revenue['total_won_revenue']['formatted_total'] ?? ($revenue['total_won_revenue']['current'] ?? 0)],
            ['Valor de pedidos perdidos', $revenue['total_lost_revenue']['formatted_total'] ?? ($revenue['total_lost_revenue']['current'] ?? 0)],
            ['Cantidad de ventas', $revenue['ventas_count']['current'] ?? 0],
        ];
    }
}
