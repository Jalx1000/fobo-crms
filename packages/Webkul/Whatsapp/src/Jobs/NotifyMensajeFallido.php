<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\Whatsapp\Mail\MensajeFallido;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Avisa por mail que un mensaje de WhatsApp no llegó, con el motivo que dio Meta.
 *
 * El motivo no queda en ningún otro lado: el inbox sólo marca el mensaje en rojo.
 * Sin esto, "no le llega" a un cliente no tiene pista (¿número sin WhatsApp?
 * ¿plantilla mal armada? ¿Meta frenando marketing?).
 */
class NotifyMensajeFallido implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $messageId, public string $motivo) {}

    /**
     * El motivo legible a partir de los `errors` del webhook de estados de Meta.
     *
     * @param  array<int, array<string, mixed>>  $errores
     */
    public static function motivoDeMeta(array $errores): string
    {
        if (! $errores) {
            return 'Meta lo marcó como fallido sin decir por qué.';
        }

        return collect($errores)->map(function ($e) {
            $detalle = $e['error_data']['details'] ?? $e['message'] ?? null;

            return trim(($e['code'] ?? '?').' — '.($e['title'] ?? 'Error').($detalle ? ': '.$detalle : ''));
        })->implode("\n");
    }

    public function handle(): void
    {
        $destinos = array_values(array_filter(array_map('trim', explode(',', (string) config('whatsapp.fallos.emails')))));

        if (! $destinos) {
            return;
        }

        $mensaje = MessageProxy::modelClass()::with('conversation')->find($this->messageId);

        if (! $mensaje) {
            return;
        }

        try {
            Mail::to($destinos)->send(new MensajeFallido(
                mensaje: $mensaje,
                motivo: $this->motivo,
                inboxUrl: route('admin.whatsapp.index').'?conversation='.$mensaje->conversation_id,
            ));
        } catch (Throwable $e) {
            // El mensaje ya falló; que tampoco salga el aviso no puede tirar el webhook.
            Log::warning('WhatsApp: no se pudo avisar del mensaje fallido', [
                'message_id' => $mensaje->id,
                'motivo'     => $this->motivo,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
