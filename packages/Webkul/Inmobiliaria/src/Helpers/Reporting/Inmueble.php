<?php

namespace Webkul\Inmobiliaria\Helpers\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Estadísticas de cartera para el tablero.
 *
 * Reemplaza a las tarjetas de productos, que en una inmobiliaria no dicen nada:
 * agregaban `lead_products`, y acá lo que se ofrece y se cierra son inmuebles,
 * que viven en `inmueble_lead`.
 *
 * Se consulta con el query builder y no con los modelos porque son agregaciones
 * de solo lectura: un modelo acá solo agregaría hidratación que nadie usa.
 */
class Inmueble
{
    protected Carbon $startDate;

    protected Carbon $endDate;

    public function __construct()
    {
        $this->startDate = now()->startOfMonth();
        $this->endDate = now();
    }

    public function setStartDate(?Carbon $startDate = null): self
    {
        $this->startDate = $startDate ? $startDate->startOfDay() : now()->startOfMonth();

        return $this;
    }

    public function setEndDate(?Carbon $endDate = null): self
    {
        $this->endDate = $endDate && $endDate <= now() ? $endDate->endOfDay() : now();

        return $this;
    }

    /**
     * Los inmuebles por los que más gente preguntó.
     *
     * Cuenta leads distintos, no filas: si un mismo lead quedó vinculado al
     * inmueble dos veces —lo pidió él y después se lo ofreció el asesor— sigue
     * siendo una persona interesada, no dos.
     *
     * Para el gerente es la señal de qué mover y qué renegociar con el dueño:
     * un inmueble con 9 consultas y sin cierre tiene un problema de precio o de
     * fotos, no de demanda.
     */
    public function getMasSolicitados(?int $limit = 5): Collection
    {
        return $this->base()
            ->orderByDesc('total_qty_ordered')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->fila($row));
    }

    /**
     * Los inmuebles que efectivamente se cerraron.
     *
     * «Cerrado» usa las mismas etapas que el resto del tablero
     * (`config('reporting.stages.won')`), para que esta tarjeta no pueda
     * contradecir a la de ventas por vendedor.
     */
    public function getCerrados(?int $limit = 5): Collection
    {
        $won = $this->etapasGanadas();

        if (! $won) {
            return collect();
        }

        return $this->base()
            ->whereIn('leads.lead_pipeline_stage_id', $won)
            ->orderByDesc('total_qty_ordered')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->fila($row));
    }

    /**
     * Estado de la cartera: cuántos hay de cada estado y cuántos sin fotos.
     *
     * Un inmueble sin fotos es trabajo pendiente invisible: el agente de IA no
     * lo puede ofrecer bien y nadie se entera hasta que un cliente las pide.
     *
     * @return array<string, mixed>
     */
    public function getSaludDeCartera(): array
    {
        $porEstado = DB::table('inmuebles')
            ->whereNull('deleted_at')
            // Asesor: la cartera de la que es titular (spec tablero-por-asesor).
            ->when(is_numeric(request('user_id')) ? request('user_id') : null, fn ($q, $userId) => $q->where('inmuebles.user_id', $userId))
            ->selectRaw('estado, COUNT(*) total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->all();

        $sinFotos = DB::table('inmuebles')
            ->whereNull('deleted_at')
            // Asesor: la cartera de la que es titular (spec tablero-por-asesor).
            ->when(is_numeric(request('user_id')) ? request('user_id') : null, fn ($q, $userId) => $q->where('inmuebles.user_id', $userId))
            ->whereNotExists(function ($q) {
                $q->from('inmueble_medias')
                    ->whereColumn('inmueble_medias.inmueble_id', 'inmuebles.id')
                    ->where('tipo', 'foto');
            })
            ->count();

        return [
            'por_estado' => $porEstado,
            'total'      => array_sum($porEstado),
            'sin_fotos'  => $sinFotos,
        ];
    }

    /**
     * La consulta común: inmuebles con su cantidad de leads interesados.
     *
     * El rango de fechas se aplica sobre el lead y no sobre el inmueble: lo que
     * interesa es cuándo hubo interés, no cuándo se cargó la propiedad.
     */
    protected function base()
    {
        return DB::table('inmueble_lead')
            ->join('inmuebles', 'inmueble_lead.inmueble_id', '=', 'inmuebles.id')
            ->join('leads', 'inmueble_lead.lead_id', '=', 'leads.id')
            ->whereNull('inmuebles.deleted_at')
            ->whereBetween('leads.created_at', [$this->startDate, $this->endDate])
            ->when(request('user_id'), fn ($q) => $q->where('leads.user_id', request('user_id')))
            ->when(
                is_numeric(request('pipeline_id')) ? request('pipeline_id') : null,
                fn ($q, $pipelineId) => $q->where('leads.lead_pipeline_id', $pipelineId)
            )
            ->select('inmuebles.id', 'inmuebles.codigo', 'inmuebles.titulo', 'inmuebles.precio', 'inmuebles.moneda')
            ->selectRaw('COUNT(DISTINCT '.DB::getTablePrefix().'inmueble_lead.lead_id) as total_qty_ordered')
            ->groupBy('inmuebles.id', 'inmuebles.codigo', 'inmuebles.titulo', 'inmuebles.precio', 'inmuebles.moneda');
    }

    /**
     * Mismas claves que usaban las tarjetas de productos, para no tocar la
     * plantilla: `name` y `total_qty_ordered` son lo que lee el gráfico.
     *
     * @return array<string, mixed>
     */
    protected function fila(object $row): array
    {
        return [
            'id'                => $row->id,
            // Código primero: es como el asesor nombra un inmueble.
            'name'              => $row->codigo.' · '.$row->titulo,
            'total_qty_ordered' => (int) $row->total_qty_ordered,
            // En la moneda guardada, nunca convertido: el tablero mezcla USD y
            // Bs y convertir acá inventaría un tipo de cambio.
            'formatted_price'   => $row->moneda.' '.number_format((float) $row->precio, 0, ',', '.'),
        ];
    }

    /**
     * @return array<int, int>
     */
    protected function etapasGanadas(): array
    {
        $codigos = array_filter(array_map('trim', (array) config('reporting.stages.won', [])));

        if (! $codigos) {
            return [];
        }

        return DB::table('lead_pipeline_stages')->whereIn('code', $codigos)->pluck('id')->all();
    }
}
