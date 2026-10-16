<?php

namespace Webkul\Admin\Helpers\Reporting;

use Webkul\Quote\Repositories\QuoteRepository;

class Quote extends AbstractReporting
{
    /**
     * Create a helper instance.
     *
     * @return void
     */
    public function __construct(protected QuoteRepository $quoteRepository)
    {
        parent::__construct();
    }

    /**
     * Retrieves total quotes and their progress.
     */
    public function getTotalQuotesProgress(): array
    {
        return [
            'previous' => $previous = $this->getTotalQuotes($this->lastStartDate, $this->lastEndDate),
            'current'  => $current = $this->getTotalQuotes($this->startDate, $this->endDate),
            'progress' => $this->getPercentageChange($previous, $current),
        ];
    }

    /**
     * Retrieves total quotes by date
     *
     * @param  \Carbon\Carbon  $startDate
     * @param  \Carbon\Carbon  $endDate
     */
    public function getTotalQuotes($startDate, $endDate): int
    {
        // Un ejecutivo cuenta solo sus cotizaciones (spec tablero-por-asesor): las
        // cotizaciones siguen al asesor del pedido (Responsables::cotizacionesConElPedido).
        // Administrador y Supervisores: las del ejecutivo elegido en el tablero.
        return \Webkul\Admin\Helpers\FiltroEjecutivo::limitarTablero(
            $this->quoteRepository->resetModel()->whereBetween('quotes.created_at', [$startDate, $endDate]),
            'quotes.user_id'
        )->count();
    }
}
