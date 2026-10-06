<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\EnlaceDeMapa;

/**
 * Convierte el link de Maps que pegó el cliente en coordenadas, y las guarda en
 * el `payload` del mensaje — donde ya las busca todo el resto.
 *
 * Va en un job y no en el gateway porque sale a la red: el gateway sólo
 * interpreta lo que Meta mandó, y una llamada HTTP adentro de parseWebhook
 * atrasa el 200 que Meta espera.
 *
 * Se encadena ANTES de NotifyAgent a propósito (ver IngestInboundPayload): si
 * corrieran en paralelo, el agente podría recibir el evento todavía sin
 * coordenadas, que es exactamente el problema que esto viene a resolver.
 */
class ResolverEnlaceDeMapa implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $message = MessageProxy::modelClass()::find($this->messageId);

        if (! $message) {
            return;
        }

        $payload = (array) $message->payload;

        // Un pin nativo ya trae el nodo: no hay nada que resolver.
        if (isset($payload['location'])) {
            return;
        }

        $punto = EnlaceDeMapa::coordenadas($message->body);

        if (! $punto) {
            return;
        }

        /*
         * Se escribe el nodo del proveedor y NO se toca ni `body` ni `type`: el
         * cliente escribió un texto y eso es lo que tiene que seguir leyendo el
         * asesor en el hilo. El tipo sigue siendo 'text'; `payload.location` es
         * un dato de más, no una reinterpretación del mensaje.
         */
        $payload['location'] = array_filter(
            $punto,
            fn ($v) => $v !== null,
        );
        $payload['location_origen'] = 'enlace';

        $message->forceFill(['payload' => $payload])->save();

        Log::info('WhatsApp: link de mapa resuelto a coordenadas.', [
            'message_id' => $message->id,
            'lat'        => $punto['latitude'],
            'lng'        => $punto['longitude'],
        ]);
    }
}
