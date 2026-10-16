<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Lead\Models\Stage;
use Webkul\Lead\Services\PipelineDeCiudad;
use Webkul\Product\Models\ProductProxy;
use Webkul\Product\Services\Responsables;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Pedido sin ciudad: a la hora, el asesor nacional de SU PRODUCTO
 * (spec docs/specs/asignar-sin-ciudad.md, 16/10/2026).
 *
 * El pedido nace con un asesor por turno, antes de saber el producto (el
 * agente lo pregunta después). Si a la hora sigue sin ciudad y nadie lo
 * atendió, se le pone el asesor de cobertura nacional («Bolivia» en la matriz
 * de reparto) que atiende su producto. No deriva, no apaga la IA y no manda
 * correos: solo cambia el dueño.
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

        $productoId = DB::table('lead_products')->where('lead_id', $lead->id)->orderBy('id')->value('product_id');
        $producto = $productoId ? ProductProxy::modelClass()::find($productoId) : null;

        // No rota por rotar: si el actual ya es válido, se queda con su cliente.
        if ($this->esValido($responsables, $producto, $lead->user_id ? (int) $lead->user_id : null)) {
            return;
        }

        $nuevo = $producto
            ? $responsables->siguiente($producto, null)
            : $responsables->asesorPorDefecto();

        if (! $nuevo || (int) $nuevo->id === (int) $lead->user_id) {
            return;
        }

        DB::table('leads')->where('id', $lead->id)->update(['user_id' => $nuevo->id, 'updated_at' => now()]);

        // Contacto, cotizaciones y conversación asignada siguen al pedido.
        Responsables::todoConElPedido((int) $lead->id);

        Log::info('Pedido sin ciudad asignado al asesor de su producto.', [
            'lead' => $lead->id, 'de' => $lead->user_id, 'a' => $nuevo->id, 'producto' => $producto?->name,
        ]);
    }

    /**
     * Sigue sin ciudad, en NO ATENDIDOS, sin derivación abierta y sin que
     * ningún asesor le haya escrito. Si algo de eso cambió, no se toca.
     */
    protected function sigueEsperando(object $lead): bool
    {
        $etapa = DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code');

        if (! static::esSinCiudad((int) $lead->lead_pipeline_id) || ! in_array($etapa, Stage::NO_ATENDIDOS, true)) {
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
     * ¿El asesor actual ya es de los que corresponden? Con producto: uno de
     * sus responsables nacionales. Sin producto: uno del turno «sin ciudad»
     * (cobertura nacional y no fuera del turno).
     */
    protected function esValido(Responsables $responsables, $producto, ?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        if ($producto) {
            return $responsables->candidatos($producto, null)->pluck('user_id')->map(fn ($id) => (int) $id)->contains($userId);
        }

        $fuera = array_map('strtolower', array_filter(array_map('trim', (array) config('cotizacion.fuera_del_turno', []))));
        $email = strtolower((string) DB::table('users')->where('id', $userId)->value('email'));

        return $responsables->esSinCiudad($userId) && ! in_array($email, $fuera, true);
    }

    protected static function esSinCiudad(int $pipelineId): bool
    {
        $nombre = (string) DB::table('lead_pipelines')->where('id', $pipelineId)->value('name');

        return mb_strtolower(trim($nombre)) === mb_strtolower(PipelineDeCiudad::SIN_CIUDAD);
    }
}
