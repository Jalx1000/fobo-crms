<?php

namespace Webkul\Inmobiliaria\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Inmobiliaria\Services\SinInteres;
use Webkul\Lead\Models\LeadProxy;

/**
 * Mueve a «Sin interés» los leads cuyo cliente no escribe hace N días.
 *
 * Solo leads de WhatsApp (con conversación), solo desde las etapas de
 * `inmobiliaria.inmuebles.sin_interes.etapas_automaticas`, y nunca con una
 * derivación vigente: si un asesor la tiene tomada, el silencio puede ser que
 * están hablando por teléfono.
 */
class MarcarLeadsSinInteres extends Command
{
    protected $signature = 'crm:leads-sin-interes
                            {--dry-run : Muestra qué leads movería, sin moverlos}';

    protected $description = 'Mueve a Sin interés los leads sin respuesta del cliente en N días';

    public function __construct(protected SinInteres $sinInteres)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $config = config('inmobiliaria.inmuebles.sin_interes');
        $dias = max(1, (int) $config['dias']);
        $limite = now()->subDays($dias);
        $simular = (bool) $this->option('dry-run');

        $etapas = DB::table('lead_pipeline_stages')->whereIn('code', (array) $config['etapas_automaticas'])->pluck('id');

        if ($etapas->isEmpty()) {
            $this->warn('Ninguna etapa coincide con LEADS_SIN_INTERES_DESDE; no se mueve nada.');

            return self::SUCCESS;
        }

        $leads = LeadProxy::modelClass()::with(['stage', 'pipeline'])
            ->whereIn('lead_pipeline_stage_id', $etapas)
            ->whereNotNull('person_id')
            ->get();

        $movidos = 0;

        foreach ($leads as $lead) {
            $conversation = DB::table('whatsapp_conversations')
                ->where('person_id', $lead->person_id)
                ->orderByDesc('last_inbound_at')
                ->first();

            if (! $conversation) {
                continue;
            }

            // Sin mensajes del cliente todavía: cuenta desde que se creó el lead.
            $ultimo = $conversation->last_inbound_at ?? $lead->created_at;

            if (! $ultimo || now()->parse($ultimo)->gt($limite)) {
                continue;
            }

            $derivada = DB::table('whatsapp_handoffs')
                ->where('conversation_id', $conversation->id)
                ->whereIn('state', ['abierto', 'tomado'])
                ->exists();

            if ($derivada) {
                $this->line("  lead #{$lead->id} salteado: derivación vigente");

                continue;
            }

            if ($simular) {
                $this->line("  lead #{$lead->id} «{$lead->title}» — último mensaje del cliente {$ultimo}");
                $movidos++;

                continue;
            }

            try {
                if ($this->sinInteres->mover($lead, "el cliente no escribe hace {$dias} días.")) {
                    $this->info("  lead #{$lead->id} movido a Sin interés.");
                    $movidos++;
                }
            } catch (Throwable $e) {
                $this->error("  lead #{$lead->id} falló: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info(($simular ? '[simulación] ' : '')."{$movidos} lead(s) a Sin interés (sin respuesta en {$dias} días).");

        return self::SUCCESS;
    }
}
