<?php

namespace Webkul\Whatsapp\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Gateways\MediaDemasiadoGrande;
use Webkul\Whatsapp\Gateways\UnsupportedCapability;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Descarga el archivo de un mensaje entrante y completa su fila.
 *
 * Corre fuera del ingest a propósito: el webhook tiene que acusar recibo a Meta
 * rápido o reintenta y duplica el mensaje, y una foto de 15 MB no puede
 * retrasar ni ese acuse ni el aviso al agente.
 *
 * Cada intento vuelve a resolver el id contra el proveedor. Las URLs de descarga
 * viven pocos minutos, así que reusar una de un intento anterior falla siempre.
 */
class DescargarMediaEntrante implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(public int $messageId) {}

    public function handle(GatewayManager $gateways): void
    {
        $message = MessageProxy::modelClass()::with('conversation')->find($this->messageId);

        if (! $message || ! $message->conversation) {
            return;
        }

        // Ya descargado: un reintento tardío no debe volver a bajarlo.
        if (filled($message->media_path)) {
            return;
        }

        $mediaId = $this->mediaIdDe($message);

        if (! $mediaId) {
            return;
        }

        try {
            $archivo = $gateways->for($message->conversation)->downloadMedia(
                $mediaId,
                $this->limitePara($message->type),
            );
        } catch (MediaDemasiadoGrande|UnsupportedCapability $e) {
            // Permanentes por naturaleza: reintentar da el mismo resultado tres
            // veces. Se anota el motivo y se termina.
            $this->rendirse($message, $e->getMessage());

            return;
        } catch (RequestException $e) {
            /*
             * Se distingue igual que con el agente: un 4xx significa que el
             * pedido está mal o el archivo ya no está, y reintentarlo no cambia
             * nada; un 5xx es del proveedor y sí merece backoff.
             */
            if ($e->response->clientError()) {
                $this->rendirse($message, 'El proveedor rechazó la descarga ('.$e->response->status().').');

                return;
            }

            throw $e;
        }

        // El proveedor dice que el archivo ya no existe. Caso normal en
        // conversaciones viejas, no un fallo que reintentar.
        if (! $archivo) {
            $this->rendirse($message, 'El archivo ya no está disponible en el proveedor.');

            return;
        }

        $nombre = $message->media_name
            ?: $mediaId.'.'.$archivo->extension();

        $path = "whatsapp/{$message->conversation_id}/inbound/".Str::random(24).'.'.$archivo->extension();

        Storage::disk('local')->put($path, $archivo->contents);

        $message->forceFill([
            'media_path' => $path,
            'media_mime' => $archivo->mime,
            'media_name' => $nombre,
            'error'      => null,
        ])->save();
    }

    /**
     * Se agotaron los intentos o el fallo es definitivo.
     *
     * El motivo va a la columna `error`, que el hilo muestra: un mensaje sin
     * archivo Y sin explicación obliga al asesor a adivinar por qué el cliente
     * "no mandó nada".
     */
    public function failed(Throwable $e): void
    {
        $message = MessageProxy::modelClass()::find($this->messageId);

        if ($message && blank($message->media_path)) {
            $message->forceFill([
                'error' => 'No se pudo descargar el archivo. '.Str::limit($e->getMessage(), 200),
            ])->save();
        }
    }

    protected function rendirse(Message $message, string $motivo): void
    {
        $message->forceFill(['error' => $motivo])->save();

        Log::info('WhatsApp: archivo entrante no descargado.', [
            'message_id' => $message->id,
            'motivo'     => $motivo,
        ]);
    }

    /**
     * El id vive en el payload crudo que el ingest ya guardó, bajo la clave del
     * tipo. No se duplica en una columna propia: es del proveedor y solo sirve
     * para esto.
     */
    protected function mediaIdDe(Message $message): ?string
    {
        $payload = (array) ($message->payload ?? []);
        $clave = $payload['type'] ?? $message->type;

        return $payload[$clave]['id'] ?? null;
    }

    protected function limitePara(string $tipo): int
    {
        $limites = config('whatsapp.inbound_media.max_bytes');

        return (int) ($limites[$tipo] ?? $limites['default']);
    }
}
