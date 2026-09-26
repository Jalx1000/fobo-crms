<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Whatsapp\Services\LeadOpener;

/**
 * Manda a "Descartado" los pedidos vacíos que quedaron al lado de uno real.
 *
 * Limpia lo que ya pasó; que no vuelva a pasar lo cubre el listener
 * DiscardEmptyWhatsappLead. Mismo criterio en los dos, para que no haya dos
 * definiciones de "fantasma" conviviendo.
 */
class DiscardGhostLeads extends Command
{
    protected $signature = 'whatsapp:descartar-pedidos-vacios
        {--desde=2026-08-01 : No tocar pedidos anteriores a esta fecha}
        {--dry-run : Mostrar qué se movería, sin escribir}';

    protected $description = 'Mueve a "Descartado" los pedidos automáticos vacíos que tienen un pedido real al lado';

    public function handle(LeadOpener $opener): int
    {
        $seco = (bool) $this->option('dry-run');
        $desde = (string) $this->option('desde');

        $candidatos = LeadProxy::modelClass()::query()
            ->where('title', 'like', LeadOpener::TITLE_PREFIX.'%')
            ->where('created_at', '>=', $desde)
            ->orderBy('id')
            ->get();

        $this->line('Pedidos automáticos desde '.$desde.': '.$candidatos->count().($seco ? '   — SIMULACIÓN' : ''));
        $this->newLine();

        $movidos = 0;
        $intocados = 0;
        $unicos = 0;
        $porAsesor = [];

        foreach ($candidatos as $fantasma) {
            // Lo tocó alguien: deja de ser fantasma.
            if (! $opener->isUntouched($fantasma)) {
                $intocados++;

                continue;
            }

            $real = $this->pedidoRealDe($fantasma);

            /*
             * Sin pedido real al lado, el vacío SÍ sirve: esa persona escribió
             * y nadie le vendió. Es justamente para lo que se abre.
             */
            if (! $real) {
                $unicos++;

                continue;
            }

            $etapa = DB::table('lead_pipeline_stages')
                ->where('lead_pipeline_id', $fantasma->lead_pipeline_id)
                ->where('code', 'descartado')
                ->value('id');

            if (! $etapa) {
                $this->warn("  sin etapa Descartado en el pipeline de #{$fantasma->id}; corré la migración primero");

                continue;
            }

            $asesor = optional(\Webkul\User\Models\UserProxy::modelClass()::find($fantasma->user_id))->name ?? '(sin dueño)';
            $porAsesor[$asesor] = ($porAsesor[$asesor] ?? 0) + 1;

            $this->line(sprintf('  #%-5d %-26s -> Descartado   (real: #%d)', $fantasma->id, mb_substr($fantasma->title, 0, 26), $real->id));

            if (! $seco) {
                $fantasma->timestamps = false;
                $fantasma->forceFill([
                    'lead_pipeline_stage_id' => $etapa,
                    'title'                  => $fantasma->title.' (reemplazado por #'.$real->id.')',
                ])->save();
            }

            $movidos++;
        }

        $this->newLine();
        $this->info("Movidos a Descartado: {$movidos}");
        $this->line("  intactos, con contenido : {$intocados}   (alguien los usó)");
        $this->line("  vacíos sin pedido real  : {$unicos}   (se dejan: nadie le vendió a esa persona)");

        if ($porAsesor) {
            $this->newLine();
            $this->line('Se liberan del pipeline de:');
            arsort($porAsesor);
            foreach ($porAsesor as $asesor => $n) {
                $this->line(sprintf('   %-24s %d', $asesor, $n));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Un pedido del MISMO contacto que sí tiene contenido.
     */
    protected function pedidoRealDe($fantasma)
    {
        return LeadProxy::modelClass()::query()
            ->where('person_id', $fantasma->person_id)
            ->where('id', '!=', $fantasma->id)
            ->where(function ($q) {
                $q->where('lead_value', '>', 0)
                    ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('lead_products')->whereColumn('lead_products.lead_id', 'leads.id'));
            })
            ->orderBy('id')
            ->first();
    }
}
