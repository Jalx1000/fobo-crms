<?php

namespace Webkul\Admin\Helpers\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Repositories\SalesGoalRepository;

/**
 * Avance de las metas de venta de un período.
 *
 * EL AVANCE NO SE GUARDA, SE CALCULA. Un cierre se carga el lunes con fecha del
 * viernes, y una etapa se corrige hacia atrás todo el tiempo: un contador
 * incremental quedaría desfasado en la primera corrección y nadie se enteraría.
 *
 * Las etapas que cuentan como cierre salen de `config('reporting.stages.won')`,
 * las mismas que el resto del tablero. Si esta clase decidiera por su cuenta,
 * el gerente podría ver «7 cierres» en una tarjeta y «5» en la de al lado.
 */
class Metas
{
    public function __construct(protected SalesGoalRepository $goals) {}

    /**
     * Metas del período con su avance, listas para dibujar.
     *
     * @return array<string, mixed>
     */
    public function getAvance(?string $periodo = null): array
    {
        $periodo = $periodo ?: now()->format('Y-m');

        [$desde, $hasta] = $this->rango($periodo);

        $metas = $this->goals->delPeriodo($periodo);

        if ($metas->isEmpty()) {
            return [
                'periodo'        => $periodo,
                'sin_metas'      => true,
                'dias_restantes' => $this->diasRestantes($hasta),
                'metas'          => [],
            ];
        }

        $definiciones = (array) config('reporting.goals.metricas', []);
        $realizados = $this->realizados($desde, $hasta);

        $filas = $metas->map(function ($meta) use ($definiciones, $realizados) {
            $def = $definiciones[$meta->metrica] ?? null;
            $clave = $meta->user_id ?? 'equipo';
            $logrado = (float) ($realizados[$meta->metrica][$clave] ?? 0);
            $objetivo = (float) $meta->objetivo;

            return [
                'id'         => $meta->id,
                'quien'      => $meta->user->name ?? 'Equipo',
                'es_equipo'  => $meta->esDelEquipo(),
                'metrica'    => $meta->metrica,
                'etiqueta'   => $def['label'] ?? $meta->metrica,
                'objetivo'   => $objetivo,
                'logrado'    => $logrado,
                'moneda'     => $meta->moneda,
                // Se corta en 100 para la barra pero `porcentaje_real` conserva
                // el excedente: superar la meta en 140 % es información, y una
                // barra al 140 % se sale del contenedor.
                'porcentaje'      => $objetivo > 0 ? min(100, round($logrado / $objetivo * 100)) : 0,
                'porcentaje_real' => $objetivo > 0 ? round($logrado / $objetivo * 100) : 0,
            ];
        })->values()->all();

        return [
            'periodo'        => $periodo,
            'sin_metas'      => false,
            'dias_restantes' => $this->diasRestantes($hasta),
            'metas'          => $filas,
        ];
    }

    /**
     * Lo efectivamente logrado en el período, por métrica y por persona.
     *
     * Se calcula de una sola vez para todas las metas en lugar de una consulta
     * por fila: con una meta de equipo y cinco asesores serían 18 consultas
     * para dibujar una tarjeta.
     *
     * @return array<string, array<int|string, float>>
     */
    protected function realizados(Carbon $desde, Carbon $hasta): array
    {
        $won = $this->etapasGanadas();

        $cerrados = DB::table('leads')
            ->whereBetween('created_at', [$desde, $hasta])
            ->when($won, fn ($q) => $q->whereIn('lead_pipeline_stage_id', $won))
            ->when(! $won, fn ($q) => $q->whereRaw('1 = 0')) // sin etapas definidas, cero
            ->select('user_id')
            ->selectRaw('COUNT(*) as cuenta')
            ->selectRaw('COALESCE(SUM(lead_value), 0) as monto')
            ->groupBy('user_id')
            ->get();

        $todos = DB::table('leads')
            ->whereBetween('created_at', [$desde, $hasta])
            ->select('user_id')
            ->selectRaw('COUNT(*) as cuenta')
            ->groupBy('user_id')
            ->get();

        $out = ['ventas' => ['equipo' => 0], 'monto' => ['equipo' => 0], 'leads' => ['equipo' => 0]];

        foreach ($cerrados as $r) {
            $out['ventas'][$r->user_id] = (float) $r->cuenta;
            $out['monto'][$r->user_id] = (float) $r->monto;
            $out['ventas']['equipo'] += (float) $r->cuenta;
            $out['monto']['equipo'] += (float) $r->monto;
        }

        foreach ($todos as $r) {
            $out['leads'][$r->user_id] = (float) $r->cuenta;
            $out['leads']['equipo'] += (float) $r->cuenta;
        }

        return $out;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function rango(string $periodo): array
    {
        $inicio = Carbon::createFromFormat('Y-m-d', $periodo.'-01')->startOfMonth();

        return [$inicio, $inicio->copy()->endOfMonth()];
    }

    /**
     * Cuántos días quedan del período.
     *
     * Es la mitad del valor de una meta: «7 de 10» asusta el día 28 y tranquiliza
     * el día 5. Sin los días restantes, un porcentaje no se puede interpretar.
     */
    protected function diasRestantes(Carbon $hasta): int
    {
        return $hasta->isPast() ? 0 : (int) ceil(now()->diffInDays($hasta, false));
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
