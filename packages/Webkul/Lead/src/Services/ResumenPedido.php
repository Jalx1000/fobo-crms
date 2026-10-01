<?php

namespace Webkul\Lead\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\LeadProxy;

/**
 * El detalle de un pedido: qué se pidió, cuánto y por cuánto.
 *
 * Los tres datos que un asesor busca al abrir una oportunidad estaban
 * repartidos —los productos en una pestaña, las cotizaciones en otra, y el
 * valor en un campo que nadie actualizaba (todos los pedidos valían 0)—.
 *
 * Nada de esto se carga a mano: se calcula de lo que el pedido ya tiene, así
 * que no puede quedar desactualizado.
 *
 * DE DÓNDE SALE, en este orden:
 *
 *   1. Si el pedido tiene cotizaciones vinculadas, manda la ÚLTIMA. Las
 *      anteriores son versiones del mismo pedido —se recotiza al bajar el
 *      precio o cambiar la cantidad—, así que sumarlas daría un valor inflado.
 *   2. Si no hay cotizaciones, los productos cargados en el pedido.
 *   3. Si no hay ninguno de los dos, el pedido no vale nada todavía: cero.
 */
class ResumenPedido
{
    /**
     * @return array{lineas: array<int, array{producto: string, cantidad: float, importe: float}>, productos: string[], cantidad: float, valor: float, origen: string}
     */
    public function de($lead): array
    {
        $lead = is_numeric($lead) ? LeadProxy::modelClass()::find($lead) : $lead;

        if (! $lead) {
            return $this->vacio();
        }

        $cotizacion = $lead->quotes()->orderByDesc('quotes.id')->first();

        if ($cotizacion) {
            $items = $cotizacion->items;

            return [
                // Una línea por ítem: un pedido de hules Y tapas son dos
                // renglones, no «Hules · Tapas» con las cantidades sumadas,
                // que no dice cuánto se pidió de cada uno.
                'lineas'    => $items->map(fn ($i) => [
                    'producto' => (string) $i->name,
                    'cantidad' => (float) $i->quantity,
                    'importe'  => (float) $i->total,
                ])->values()->all(),
                'productos' => $items->pluck('name')->filter()->unique()->values()->all(),
                'cantidad'  => (float) $items->sum('quantity'),
                'valor'     => (float) $cotizacion->grand_total,
                'origen'    => 'cotizacion',
            ];
        }

        $productos = DB::table('lead_products as lp')
            ->leftJoin('products as p', 'p.id', '=', 'lp.product_id')
            ->where('lp.lead_id', $lead->id)
            ->select('p.name', 'lp.quantity', 'lp.amount')
            ->get();

        if ($productos->isEmpty()) {
            return $this->vacio();
        }

        return [
            'lineas'    => $productos->map(fn ($p) => [
                'producto' => (string) ($p->name ?: '—'),
                'cantidad' => (float) $p->quantity,
                'importe'  => (float) $p->amount,
            ])->values()->all(),
            'productos' => $productos->pluck('name')->filter()->unique()->values()->all(),
            'cantidad'  => (float) $productos->sum('quantity'),
            'valor'     => (float) $productos->sum('amount'),
            'origen'    => 'productos',
        ];
    }

    /**
     * Deja el valor calculado en `leads.lead_value`, que es lo que miran el
     * tablero, el embudo y el panel del inbox.
     *
     * Se escribe por consulta y no con `save()`: `CustomAttribute` deja los
     * atributos personalizados pegados al modelo y el UPDATE saldría con
     * columnas que no existen.
     */
    public function actualizarValor($lead): float
    {
        $lead = is_numeric($lead) ? LeadProxy::modelClass()::find($lead) : $lead;

        if (! $lead) {
            return 0.0;
        }

        $valor = $this->de($lead)['valor'];

        DB::table('leads')->where('id', $lead->id)->update(['lead_value' => $valor]);

        return $valor;
    }

    protected function vacio(): array
    {
        return ['lineas' => [], 'productos' => [], 'cantidad' => 0.0, 'valor' => 0.0, 'origen' => 'vacio'];
    }
}
