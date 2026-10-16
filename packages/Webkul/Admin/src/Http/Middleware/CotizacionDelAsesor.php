<?php

namespace Webkul\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\Alcance;

/**
 * Un ejecutivo solo abre, imprime, envía y edita SUS cotizaciones (spec
 * docs/specs/tablero-por-asesor.md, D13c): las que tiene a su nombre o las de
 * sus pedidos. Antes cualquier ruta `quotes/{id}` aceptaba el id de otra, y
 * «crear» aceptaba el pedido de otro. Va en el grupo de rutas: una ruta nueva
 * queda cubierta sola. 404 y no 403: no confirma que la cotización existe.
 */
class CotizacionDelAsesor
{
    public function handle(Request $request, Closure $next)
    {
        $userId = app(Alcance::class)->userId();

        if ($userId !== null) {
            if ($id = $request->route('id')) {
                abort_unless($this->esSuya((int) $id, $userId), 404);
            }

            if ($leadId = $request->route('lead_id')) {
                abort_unless(DB::table('leads')->where('id', $leadId)->where('user_id', $userId)->exists(), 404);
            }
        }

        return $next($request);
    }

    protected function esSuya(int $quoteId, int $userId): bool
    {
        return DB::table('quotes')->where('id', $quoteId)->where('user_id', $userId)->exists()
            || DB::table('lead_quotes')->join('leads', 'leads.id', '=', 'lead_quotes.lead_id')
                ->where('lead_quotes.quote_id', $quoteId)->where('leads.user_id', $userId)->exists();
    }
}
