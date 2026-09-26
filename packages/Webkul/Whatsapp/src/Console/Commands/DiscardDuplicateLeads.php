<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Lead\Support\DiscardedLeads;
use Webkul\Whatsapp\Services\LeadOpener;

/**
 * Manda a "Descartado" el duplicado de un par, cuando se puede saber cuál sobra.
 *
 * Distinto de whatsapp:descartar-pedidos-vacios: ahí el fantasma estaba vacío y
 * la decisión era obvia. Acá LOS DOS tienen productos, así que sólo se toca lo
 * que responde a un patrón medido, y el resto se lista para revisar a mano.
 *
 * Patrón A — fantasma rellenado a mano (15 casos al 22/09/2026).
 *   Uno tiene título "WhatsApp - <nombre>" y descripción vacía; el hermano tiene
 *   título propio y la descripción que arma el agente con los datos del cliente.
 *   Alguien le cargó el producto al pedido automático en vez de usar el bueno.
 *
 * Patrón B — copia exacta (1 caso).
 *   Mismo título, misma descripción, mismo monto y los mismos productos. Se
 *   conserva el más viejo.
 *
 * Lo que NO entra en ningún patrón son los pedidos donde el monto cambió
 * (#1772 Bs 150 → #1773 Bs 225): ahí no se puede saber por los datos si el
 * cliente corrigió o pidió más, y elegir mal borra una venta.
 */
class DiscardDuplicateLeads extends Command
{
    /** Ventana dentro de la cual dos pedidos iguales son el mismo pedido. */
    private const MINUTOS = 60;

    protected $signature = 'whatsapp:descartar-pedidos-duplicados
        {--desde=2026-08-01 : No tocar pedidos anteriores a esta fecha}
        {--minutos=60 : Ventana entre los dos pedidos}
        {--dry-run : Mostrar qué se movería, sin escribir}';

    protected $description = 'Descarta el duplicado de un par cuando el patrón dice cuál sobra';

    public function handle(): int
    {
        $seco = (bool) $this->option('dry-run');
        $ventana = ((int) $this->option('minutos') ?: self::MINUTOS) * 60;

        $descartados = DiscardedLeads::stageIds();

        $leads = DB::table('leads')
            ->whereNotNull('person_id')
            ->where('created_at', '>=', (string) $this->option('desde'))
            ->whereNotIn('lead_pipeline_stage_id', $descartados)
            ->orderBy('person_id')
            ->orderBy('id')
            ->get(['id', 'title', 'description', 'person_id', 'lead_value', 'created_at']);

        $productos = DB::table('lead_products')
            ->select('lead_id', DB::raw('GROUP_CONCAT(DISTINCT product_id ORDER BY product_id) as pids'))
            ->groupBy('lead_id')
            ->pluck('pids', 'lead_id');

        $aMover = [];
        $aMano = [];

        foreach ($leads->groupBy('person_id') as $delContacto) {
            $lista = $delContacto->values();

            for ($i = 1; $i < $lista->count(); $i++) {
                $a = $lista[$i - 1];
                $b = $lista[$i];

                $pa = $productos[$a->id] ?? null;
                $pb = $productos[$b->id] ?? null;

                // Los dos tienen que tener contenido y ser el mismo producto.
                if (! $pa || ! $pb || $pa !== $pb) {
                    continue;
                }

                if (strtotime($b->created_at) - strtotime($a->created_at) > $ventana) {
                    continue;
                }

                if ($sobra = $this->cualSobra($a, $b)) {
                    [$fuera, $queda, $motivo] = $sobra;
                    $aMover[] = compact('fuera', 'queda', 'motivo');
                } else {
                    $aMano[] = [$a, $b];
                }
            }
        }

        $this->mostrar($aMover, $aMano, $seco);

        if ($seco || ! $aMover) {
            return self::SUCCESS;
        }

        $movidos = 0;

        foreach ($aMover as $par) {
            if ($this->descartar($par['fuera'], $par['queda'])) {
                $movidos++;
            }
        }

        $this->newLine();
        $this->info($movidos.' pedido(s) movido(s) a Descartado.');

        return self::SUCCESS;
    }

    /**
     * ¿Cuál de los dos sobra? null cuando los datos no alcanzan para decidirlo.
     *
     * @return array{0: object, 1: object, 2: string}|null
     */
    private function cualSobra(object $a, object $b): ?array
    {
        $esFantasma = fn ($l) => str_starts_with((string) $l->title, LeadOpener::TITLE_PREFIX)
            && empty($l->description);

        // Patrón A: el automático quedó relleno y el bueno está al lado.
        if ($esFantasma($a) && ! $esFantasma($b)) {
            return [$a, $b, 'automático rellenado a mano'];
        }

        if ($esFantasma($b) && ! $esFantasma($a)) {
            return [$b, $a, 'automático rellenado a mano'];
        }

        // Patrón B: copia exacta. Se conserva el más viejo.
        if (
            $a->title === $b->title
            && $a->description === $b->description
            && (float) $a->lead_value === (float) $b->lead_value
        ) {
            return [$b, $a, 'copia exacta'];
        }

        return null;
    }

    private function descartar(object $fuera, object $queda): bool
    {
        $lead = LeadProxy::modelClass()::find($fuera->id);

        if (! $lead) {
            return false;
        }

        $etapa = DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $lead->lead_pipeline_id)
            ->where('code', DiscardedLeads::STAGE_CODE)
            ->value('id');

        if (! $etapa) {
            $this->warn('  #'.$lead->id.': su pipeline no tiene la etapa Descartado.');

            return false;
        }

        $lead->forceFill([
            'lead_pipeline_stage_id' => $etapa,
            'title'                  => $lead->title.' (duplicado de #'.$queda->id.')',
        ])->save();

        return true;
    }

    /**
     * @param  array<int, array{fuera: object, queda: object, motivo: string}>  $aMover
     * @param  array<int, array{0: object, 1: object}>  $aMano
     */
    private function mostrar(array $aMover, array $aMano, bool $seco): void
    {
        if ($aMover) {
            $this->line('Con patrón claro: '.count($aMover).($seco ? '   — SIMULACIÓN' : ''));

            $this->table(
                ['Se descarta', 'Queda', 'Valor', 'Por qué'],
                array_map(fn ($p) => [
                    '#'.$p['fuera']->id,
                    '#'.$p['queda']->id,
                    'Bs '.number_format((float) $p['queda']->lead_value, 0),
                    $p['motivo'],
                ], $aMover)
            );
        }

        if ($aMano) {
            $this->newLine();
            $this->warn('A revisar a mano: '.count($aMano).' (el monto cambió, no se puede saber cuál queda)');

            $this->table(
                ['Primero', 'Valor', 'Segundo', 'Valor', 'Pedido'],
                array_map(fn ($p) => [
                    '#'.$p[0]->id, 'Bs '.number_format((float) $p[0]->lead_value, 0),
                    '#'.$p[1]->id, 'Bs '.number_format((float) $p[1]->lead_value, 0),
                    mb_strimwidth((string) $p[1]->title, 0, 30, '…'),
                ], $aMano)
            );
        }
    }
}
