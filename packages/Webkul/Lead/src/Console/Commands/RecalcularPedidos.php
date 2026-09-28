<?php

namespace Webkul\Lead\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Services\ResumenPedido;

/**
 * Pone al día los pedidos que ya existían: los vincula con sus cotizaciones
 * sueltas y recalcula su valor.
 *
 * Hace falta una sola vez, por dos arreglos:
 *
 *   - Las cotizaciones del agente nunca se vinculaban al pedido: se le pasaba
 *     `lead_id` al repositorio y se perdía en silencio, porque `quotes` no
 *     tiene esa columna (la relación es la tabla `lead_quotes`).
 *   - `leads.lead_value` no lo escribía nadie: todos los pedidos valían 0
 *     aunque tuvieran productos y cotizaciones.
 *
 * Es idempotente: correrlo de nuevo no duplica vínculos ni cambia valores que
 * ya estén bien.
 */
class RecalcularPedidos extends Command
{
    protected $signature = 'imprimir:recalcular-pedidos {--dry-run : Solo muestra lo que haría}';

    protected $description = 'Vincula las cotizaciones sueltas a su pedido y recalcula el valor de cada pedido';

    public function handle(ResumenPedido $resumen): int
    {
        $simulacro = (bool) $this->option('dry-run');

        /*
        | El simulacro hace TODO y después deshace.
        |
        | Calcular sin escribir daría un preview falso: el valor de un pedido
        | depende de las cotizaciones que este mismo comando acaba de vincular,
        | así que sin vincularlas primero todos darían cero.
        */
        DB::beginTransaction();

        try {
            $salida = $this->recalcular($resumen, $simulacro);
        } finally {
            $simulacro ? DB::rollBack() : DB::commit();
        }

        return $salida;
    }

    protected function recalcular(ResumenPedido $resumen, bool $simulacro): int
    {
        $vinculadas = $this->vincularHuerfanas();

        $this->info($simulacro
            ? "Se vincularían {$vinculadas} cotizaciones a su pedido."
            : "Cotizaciones vinculadas a su pedido: {$vinculadas}.");

        $filas = [];

        foreach (DB::table('leads')->orderBy('id')->get(['id', 'title', 'lead_value']) as $lead) {
            $calculado = $resumen->de($lead->id);

            if (abs((float) $lead->lead_value - $calculado['valor']) < 0.01) {
                continue;
            }

            DB::table('leads')->where('id', $lead->id)->update(['lead_value' => $calculado['valor']]);

            $filas[] = [
                $lead->id,
                mb_strimwidth($lead->title, 0, 34, '…'),
                number_format((float) $lead->lead_value, 2),
                number_format($calculado['valor'], 2),
                $calculado['origen'],
            ];
        }

        if ($filas) {
            $this->table(['Pedido', 'Título', 'Valor antes', 'Valor ahora', 'Según'], $filas);
        }

        $this->info(($simulacro ? 'Cambiarían' : 'Actualizados').': '.count($filas).' pedidos.');

        return self::SUCCESS;
    }

    /**
     * Cotizaciones sin pedido cuyo contacto tiene UN solo pedido: se vinculan.
     *
     * Con dos o más no se adivina — vincularla al equivocado es peor que
     * dejarla suelta, porque el valor del pedido saldría de una cotización que
     * no le corresponde.
     */
    protected function vincularHuerfanas(): int
    {
        $sueltas = DB::table('quotes as q')
            ->leftJoin('lead_quotes as lq', 'lq.quote_id', '=', 'q.id')
            ->whereNull('lq.quote_id')
            ->whereNotNull('q.person_id')
            ->select('q.id', 'q.person_id')
            ->get();

        $n = 0;

        foreach ($sueltas as $quote) {
            $leads = DB::table('leads')->where('person_id', $quote->person_id)->pluck('id');

            if ($leads->count() !== 1) {
                continue;
            }

            DB::table('lead_quotes')->insert(['lead_id' => $leads->first(), 'quote_id' => $quote->id]);

            $n++;
        }

        return $n;
    }
}
