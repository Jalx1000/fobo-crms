<?php

namespace Webkul\Whatsapp\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Recontacto antes de que cierre la ventana de 24 h.
 *
 * Si el cliente no contestó nuestro último mensaje —del agente o de un asesor,
 * por ejemplo la cotización—, `horas_antes` horas antes del cierre se le
 * escribe UNA vez, para que responda mientras todavía se le puede escribir sin
 * plantilla. Se agenda con cada mensaje del cliente; si vuelve a escribir, el
 * agendado anterior queda sin efecto.
 *
 * En horario (whatsapp.handoff.horario_*): si el momento cae de noche se corre
 * a la apertura, o —si a la apertura la ventana ya cerró— al final del día
 * hábil anterior.
 */
class Recontactar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $conversationId, public string $lastInboundAt) {}

    public static function habilitado(): bool
    {
        return (bool) config('whatsapp.recontacto.enabled', true);
    }

    public static function agendar(Conversation $conversation): void
    {
        if (! static::habilitado() || ! $conversation->last_inbound_at) {
            return;
        }

        static::dispatch($conversation->id, $conversation->last_inbound_at->toIso8601String())
            ->onQueue(config('whatsapp.queue'))
            ->delay(static::momento($conversation->last_inbound_at, static::ventanaHoras($conversation)));
    }

    /**
     * Cuándo recontactar: `horas_antes` antes del cierre, movido a horario.
     */
    public static function momento(Carbon $ultimoDelCliente, int $ventanaHoras = 24): Carbon
    {
        $cierre = $ultimoDelCliente->copy()->addHours($ventanaHoras);
        $objetivo = $cierre->copy()->subHours((int) config('whatsapp.recontacto.horas_antes', 6));

        if (AvisarDerivacionSinRespuesta::enHorario($objetivo)) {
            return $objetivo;
        }

        // De noche: a la apertura, si la ventana todavía está abierta.
        $apertura = AvisarDerivacionSinRespuesta::proximaApertura($objetivo);

        if ($apertura->lt($cierre->copy()->subMinutes(30))) {
            return $apertura;
        }

        // Si no, al final del último día hábil antes del objetivo.
        [$h, $m] = array_map('intval', explode(':', (string) config('whatsapp.handoff.horario_fin', '19:00')));
        $local = $objetivo->copy()->setTimezone(config('app.timezone'));

        for ($i = 0; $i < 8; $i++) {
            $candidato = $local->copy()->subDays($i)->setTime($h, $m)->subMinute();

            if ($candidato->lt($local) && $candidato->gt($ultimoDelCliente) && AvisarDerivacionSinRespuesta::enHorario($candidato)) {
                return $candidato;
            }
        }

        return $objetivo;
    }

    public function handle(): void
    {
        if (! static::habilitado()) {
            return;
        }

        $conversation = ConversationProxy::modelClass()::with('person:id,name')->find($this->conversationId);

        // El cliente volvió a escribir: corre el recontacto de ese mensaje.
        if (! $conversation?->last_inbound_at
            || $conversation->last_inbound_at->toIso8601String() !== $this->lastInboundAt) {
            return;
        }

        // La ventana ya cerró: sin plantilla no se le puede escribir.
        if (now()->gte($conversation->last_inbound_at->copy()->addHours(static::ventanaHoras($conversation)))) {
            return;
        }

        $desde = $conversation->last_inbound_at;
        $salientes = $conversation->messages()->where('direction', 'outbound')->where('created_at', '>=', $desde);

        // Nadie le contestó: el pendiente es nuestro, no del cliente.
        if (! (clone $salientes)->exists()) {
            return;
        }

        // Una sola vez por ventana.
        if ((clone $salientes)->where('payload->recontacto', true)->exists()) {
            return;
        }

        $porCotizacion = (clone $salientes)->whereNotNull('payload->quote_id')->exists();

        $texto = (string) config($porCotizacion ? 'whatsapp.recontacto.texto_cotizacion' : 'whatsapp.recontacto.texto');
        $nombre = Str::of((string) ($conversation->person?->name ?? $conversation->wa_name ?? ''))->trim()->explode(' ')->first();

        $texto = trim(preg_replace('/\s+,/', ',', str_replace(':nombre', (string) $nombre, $texto)));

        $message = MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $texto,
            'status' => 'queued',
            'sender' => 'system',
            'payload' => ['recontacto' => true, 'motivo' => $porCotizacion ? 'cotizacion' : 'sin_respuesta'],
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id)->onQueue(config('whatsapp.queue'));
    }

    protected static function ventanaHoras(Conversation $conversation): int
    {
        try {
            return (int) (app(GatewayManager::class)->for($conversation)->messagingWindowHours() ?: 24);
        } catch (\Throwable) {
            return 24;
        }
    }
}
