<?php

namespace Webkul\Whatsapp\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Mail\DerivacionSinRespuesta;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\ConversationProxy;

/**
 * Control de una derivación: si nadie le contestó al cliente, avisar.
 *
 * Auditoría del 12/10: 28 de 52 derivaciones sin respuesta humana registrada y
 * el resto con ~18 h de mediana. Se agenda al derivar; al correr, si la
 * derivación sigue abierta y ningún asesor escribió desde entonces, manda un
 * correo al asesor asignado con copia fija. Un solo aviso por derivación.
 */
class AvisarDerivacionSinRespuesta implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  string  $requestedAt  El handoff_requested_at con el que se agendó:
     *                               si la conversación se volvió a derivar,
     *                               este control ya no corresponde.
     */
    public function __construct(public int $conversationId, public string $requestedAt) {}

    public static function agendar(Conversation $conversation): void
    {
        if (! $conversation->handoff_requested_at) {
            return;
        }

        static::dispatch($conversation->id, $conversation->handoff_requested_at->toIso8601String())
            ->onQueue(config('whatsapp.queue'))
            ->delay(now()->addMinutes(static::minutos()));
    }

    public function handle(): void
    {
        $conversation = ConversationProxy::modelClass()::with('person:id,name')->find($this->conversationId);

        if (! $conversation
            || ! $conversation->handoffOpen()
            || optional($conversation->handoff_requested_at)->toIso8601String() !== $this->requestedAt) {
            return;
        }

        /*
        | Corrió antes de tiempo (cola `sync`, o un worker que ignora el
        | retardo): no se avisa ni se reagenda —con `sync` reagendar correría de
        | nuevo al instante—. En producción (Redis) el retardo se respeta.
        */
        if (now()->lt($conversation->handoff_requested_at->copy()->addMinutes(static::minutos()))) {
            return;
        }

        $respondida = $conversation->messages()
            ->where('direction', 'outbound')
            ->where('sender', 'human')
            ->where('created_at', '>=', $conversation->handoff_requested_at)
            ->exists();

        if ($respondida) {
            return;
        }

        // Fuera de horario no se avisa: se vuelve a mirar a la apertura.
        if (! static::enHorario(now())) {
            static::dispatch($this->conversationId, $this->requestedAt)
                ->onQueue(config('whatsapp.queue'))
                ->delay(static::proximaApertura(now())->addMinutes(static::minutos()));

            return;
        }

        $asesor = $conversation->assigned_user_id
            ? UserProxy::modelClass()::where('id', $conversation->assigned_user_id)->where('status', 1)->first()
            : null;

        $copia = (array) config('whatsapp.handoff.sin_respuesta_copia', []);

        $para = $asesor?->email ? [$asesor->email] : $copia;

        if (! $para) {
            return;
        }

        $correo = new DerivacionSinRespuesta(
            $conversation,
            (int) $conversation->handoff_requested_at->diffInMinutes(now()),
            $asesor?->name,
        );

        try {
            $envio = Mail::to($para);

            if ($asesor?->email && $copia) {
                $envio->cc($copia);
            }

            $envio->send($correo);
        } catch (\Throwable $e) {
            Log::error('WhatsApp: no se pudo avisar la derivación sin respuesta.', [
                'conversation' => $conversation->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    protected static function minutos(): int
    {
        return max(1, (int) config('whatsapp.handoff.sin_respuesta_minutos', 30));
    }

    public static function enHorario(Carbon $momento): bool
    {
        $local = $momento->copy()->setTimezone(config('app.timezone'));

        if (! in_array($local->dayOfWeekIso, (array) config('whatsapp.handoff.horario_dias', [1, 2, 3, 4, 5, 6]), true)) {
            return false;
        }

        $hora = $local->format('H:i');

        return $hora >= config('whatsapp.handoff.horario_inicio', '08:00')
            && $hora < config('whatsapp.handoff.horario_fin', '19:00');
    }

    public static function proximaApertura(Carbon $desde): Carbon
    {
        $local = $desde->copy()->setTimezone(config('app.timezone'));
        [$h, $m] = array_map('intval', explode(':', (string) config('whatsapp.handoff.horario_inicio', '08:00')));

        for ($i = 0; $i < 8; $i++) {
            $candidata = $local->copy()->addDays($i)->setTime($h, $m);

            if ($candidata->gt($local)
                && in_array($candidata->dayOfWeekIso, (array) config('whatsapp.handoff.horario_dias', [1, 2, 3, 4, 5, 6]), true)) {
                return $candidata;
            }
        }

        return $local->copy()->addDay()->setTime($h, $m);
    }
}
