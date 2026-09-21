<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Whatsapp\Models\Handoff;
use Webkul\Whatsapp\Repositories\HandoffRepository;

/**
 * Cierra las derivaciones que nadie contestó.
 *
 * Mientras hay una derivación vigente el agente de IA queda mudo. Si el asesor
 * no aparece, esa garantía se vuelve el problema: el cliente escribe y no le
 * contesta nadie, ni la persona ni el agente. Pasadas las horas configuradas
 * sin una sola respuesta humana, la conversación vuelve al agente.
 *
 * «Sin respuesta» se mide contra mensajes salientes escritos por una persona,
 * no contra la hora de apertura: una derivación donde el asesor contestó y
 * siguió la charla no se toca, por vieja que sea.
 */
class CerrarHandoffsAbandonados extends Command
{
    protected $signature = 'crm:cerrar-handoffs-abandonados
                            {--dry-run : Muestra cuáles se cerrarían sin tocar nada}';

    protected $description = 'Cierra las derivaciones que nadie contestó y devuelve la conversación al agente';

    public function __construct(
        protected HandoffRepository $handoffs,
        protected ActivityRepository $activities,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $simular = (bool) $this->option('dry-run');

        $horas = (int) config('inmobiliaria.inmuebles.handoff.cerrar_sin_respuesta_horas', 8);

        $abandonadas = $this->handoffs->abandonadas($horas);

        if ($abandonadas->isEmpty()) {
            $this->info("Ninguna derivación lleva {$horas} h sin respuesta.");

            return self::SUCCESS;
        }

        $this->info(($simular ? 'SIMULACIÓN — se cerrarían ' : 'Cerrando ')
            .$abandonadas->count()." derivación(es) sin respuesta en {$horas} h");

        foreach ($abandonadas as $handoff) {
            $espera = (int) $handoff->opened_at->diffInHours(now());

            $this->line("  #{$handoff->id} · conversación {$handoff->conversation_id}"
                ." · {$handoff->asesor?->name} · {$espera} h sin contestar");

            if ($simular) {
                continue;
            }

            try {
                $this->cerrar($handoff, $horas);
            } catch (Throwable $e) {
                // Una que falla no puede dejar sin cerrar a las demás.
                $this->error('     no se pudo cerrar: '.$e->getMessage());

                Log::warning('WhatsApp: no se pudo cerrar una derivación abandonada.', [
                    'handoff_id' => $handoff->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Cierra una y deja la constancia en el timeline del cliente.
     */
    protected function cerrar(Handoff $handoff, int $horas): void
    {
        $this->handoffs->cerrar($handoff->conversation_id, null, Handoff::POR_SISTEMA);

        $conversation = $handoff->conversation;

        if (! $conversation) {
            return;
        }

        // Vuelve a atender el agente: es lo único que queda entre el cliente y
        // el silencio, ya que la persona no apareció.
        $conversation->update(['ai_enabled' => true]);

        $this->auditar($conversation, 'El sistema cerró la derivación: nadie contestó en '
            .$horas.' horas'.($handoff->asesor ? ' (asignada a '.$handoff->asesor->name.')' : '')
            .'. El agente IA vuelve a atender.');
    }

    /**
     * Nota de actividad sobre la persona y el lead, que es de donde el detalle
     * del cliente arma su timeline.
     */
    protected function auditar($conversation, string $comment): void
    {
        if (! $conversation->person_id && ! $conversation->lead_id) {
            return;
        }

        $activity = $this->activities->create([
            'type'    => 'note',
            'comment' => $comment,
            'is_done' => 1,
            // Sin user_id a propósito: no lo hizo nadie, lo hizo el sistema.
            'user_id' => null,
        ]);

        if ($conversation->person_id) {
            \Webkul\Contact\Models\PersonProxy::modelClass()::find($conversation->person_id)
                ?->activities()->attach($activity->id);
        }

        if ($conversation->lead_id) {
            \Webkul\Lead\Models\LeadProxy::modelClass()::find($conversation->lead_id)
                ?->activities()->attach($activity->id);
        }
    }
}
