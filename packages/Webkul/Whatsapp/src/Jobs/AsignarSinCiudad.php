<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\CiudadCliente;
use Webkul\Lead\Models\Stage;
use Webkul\Lead\Services\PipelineDeCiudad;
use Webkul\Lead\Services\ProductoDelPedido;
use Webkul\Product\Models\ProductProxy;
use Webkul\Product\Services\Responsables;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Services\CiudadDesdeChat;

/**
 * Pedido que nació sin ciudad: a la hora, el asesor que le corresponde según
 * lo que el cliente dijo en esa hora (spec docs/specs/asignar-sin-ciudad.md,
 * v1 y v2 del 16/10/2026).
 *
 * El pedido nace con un asesor por turno, antes de saber nada. A la hora, si
 * nadie lo atendió, se mira la ciudad (si la dijo) y el producto (el del
 * pedido o el que nombró en el chat: #6610 preguntó por «Stretch Film» y
 * seguía con Eduardo) y se aplica la matriz de reparto. No deriva, no apaga
 * la IA y no manda correos: solo cambia el dueño y carga el producto.
 */
class AsignarSinCiudad implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $leadId) {}

    public static function minutos(): int
    {
        return (int) config('cotizacion.sin_ciudad.asignar_minutos', 60);
    }

    /**
     * Se agenda al crear el pedido, solo si nace en «Sin ciudad».
     */
    public static function agendar(int $leadId, int $pipelineId): void
    {
        if (static::minutos() <= 0 || ! static::esSinCiudad($pipelineId)) {
            return;
        }

        static::dispatch($leadId)
            ->onQueue(config('whatsapp.queue'))
            ->delay(now()->addMinutes(static::minutos()));
    }

    public function handle(Responsables $responsables): void
    {
        $lead = DB::table('leads')->find($this->leadId);

        if (! $lead || ! $this->sigueEsperando($lead)) {
            return;
        }

        $conversacion = Conversation::where('lead_id', $lead->id)->latest('id')->first();
        $ciudad = $this->ciudad($lead);
        $producto = $this->producto($lead, $conversacion);
        $actual = $lead->user_id ? (int) $lead->user_id : null;

        // No rota por rotar: si el actual ya es válido, se queda con su cliente.
        if ($actual && in_array($actual, $this->validos($responsables, $producto, $ciudad), true)) {
            return;
        }

        $nuevo = match (true) {
            // Si nadie atiende ese producto ahí, el asesor por defecto (21/10/2026).
            (bool) $producto => $responsables->siguiente($producto, $ciudad) ?? $responsables->asesorPorDefecto(),
            (bool) $ciudad   => $responsables->porCiudad($ciudad),
            default          => $responsables->asesorPorDefecto(),
        };

        if (! $nuevo || (int) $nuevo->id === $actual) {
            return;
        }

        DB::table('leads')->where('id', $lead->id)->update(['user_id' => $nuevo->id, 'updated_at' => now()]);

        // Contacto, cotizaciones y conversación asignada siguen al pedido.
        Responsables::todoConElPedido((int) $lead->id);

        Log::info('Pedido sin ciudad asignado según ciudad y producto.', [
            'lead' => $lead->id, 'de' => $actual, 'a' => $nuevo->id, 'ciudad' => $ciudad, 'producto' => $producto?->name,
        ]);
    }

    /**
     * En NO ATENDIDOS, sin derivación abierta y sin que ningún asesor le haya
     * escrito. Si dijo la ciudad (ya pasó al pipeline de su ciudad) igual se
     * revisa: pudo nombrar el producto después (v2).
     */
    protected function sigueEsperando(object $lead): bool
    {
        $etapa = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code');

        if (! in_array($etapa, Stage::NO_ATENDIDOS, true)) {
            return false;
        }

        $conversaciones = DB::table('whatsapp_conversations')->where('lead_id', $lead->id);

        if ((clone $conversaciones)->whereIn('handoff_state', Conversation::HANDOFF_OPEN_STATES)->exists()) {
            return false;
        }

        return ! DB::table('whatsapp_messages')
            ->whereIn('conversation_id', (clone $conversaciones)->select('id'))
            ->where('sender', 'human')
            ->exists();
    }

    /**
     * La del pipeline si ya pasó a una ciudad; si no, la del contacto.
     */
    protected function ciudad(object $lead): ?string
    {
        if (! static::esSinCiudad((int) $lead->lead_pipeline_id)) {
            return DB::table('lead_pipelines')->where('id', $lead->lead_pipeline_id)->value('name');
        }

        $ciudad = $lead->person_id ? app(CiudadCliente::class)->de('persons', (int) $lead->person_id) : null;

        return $ciudad && ! Responsables::esNacional($ciudad) ? $ciudad : null;
    }

    /**
     * El del pedido; si no tiene, el que el cliente nombró en el chat, que
     * queda cargado en el pedido (decisión Q2 del 16/10).
     */
    protected function producto(object $lead, ?Conversation $conversacion)
    {
        $id = DB::table('lead_products')->where('lead_id', $lead->id)->orderBy('id')->value('product_id');

        if ($id) {
            return ProductProxy::modelClass()::find($id);
        }

        $producto = $conversacion ? app(CiudadDesdeChat::class)->producto($conversacion) : null;

        if ($producto) {
            app(ProductoDelPedido::class)->registrar((int) $lead->id, (int) $producto->id);
        }

        return $producto;
    }

    /**
     * Quiénes corresponden, sin gastar el turno de nadie: con producto, sus
     * responsables en la ciudad (o los nacionales); con solo la ciudad, quien
     * atiende todo ahí; sin nada -o nadie en esa ciudad-, el turno nacional
     * «sin ciudad» (sin los de `fuera_del_turno`).
     *
     * @return array<int, int>
     */
    protected function validos(Responsables $responsables, $producto, ?string $ciudad): array
    {
        if ($producto) {
            return $responsables->candidatos($producto, $ciudad)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        }

        if ($ciudad && $locales = $responsables->atiendenTodoEn($ciudad)) {
            return $locales;
        }

        $fuera = array_map('strtolower', array_filter(array_map('trim', (array) config('cotizacion.fuera_del_turno', []))));

        return DB::table('users')
            ->where('status', 1)
            ->get(['id', 'email'])
            ->reject(fn ($u) => in_array(strtolower((string) $u->email), $fuera, true))
            ->map(fn ($u) => (int) $u->id)
            ->filter(fn ($id) => $responsables->esSinCiudad($id))
            ->values()
            ->all();
    }

    protected static function esSinCiudad(int $pipelineId): bool
    {
        $nombre = (string) DB::table('lead_pipelines')->where('id', $pipelineId)->value('name');

        return mb_strtolower(trim($nombre)) === mb_strtolower(PipelineDeCiudad::SIN_CIUDAD);
    }
}
