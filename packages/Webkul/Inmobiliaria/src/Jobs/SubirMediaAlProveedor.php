<?php

namespace Webkul\Inmobiliaria\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Webkul\Inmobiliaria\Models\InmuebleMediaProxy;
use Webkul\Whatsapp\Gateways\GatewayManager;

/**
 * Sube un archivo del catálogo al proveedor de mensajería y cachea su id.
 *
 * Corre cuando el asesor carga la foto, no cuando el agente la envía. Esa es
 * toda la idea: para cuando alguien quiera mandarla, el archivo ya está del
 * lado de Meta y el envío es una referencia. Si esto se hiciera en el momento
 * del envío, un CRM lento haría que la foto no llegue justo cuando importa.
 *
 * Fallar acá no es grave: el envío cae a URL firmada. Por eso no reintenta
 * eternamente ni marca nada como roto.
 */
class SubirMediaAlProveedor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $mediaId) {}

    public function handle(GatewayManager $gateways): void
    {
        $media = InmuebleMediaProxy::modelClass()::find($this->mediaId);

        // Un tour es una URL externa: no hay archivo que subir.
        if (! $media || blank($media->path) || $media->tipo === 'tour') {
            return;
        }

        $disco = Storage::disk('local');

        if (! $disco->exists($media->path)) {
            return;
        }

        try {
            $mediaId = $gateways->driver(config('whatsapp.gateway'))->uploadMedia(
                $disco->path($media->path),
                $disco->mimeType($media->path) ?: 'application/octet-stream',
            );

            // null = el proveedor no tiene almacén de media (Kommo). No es un
            // fallo: simplemente ese canal siempre envía por URL.
            if (! $mediaId) {
                return;
            }

            $media->update([
                'provider_media_id'  => $mediaId,
                'provider_expira_at' => now()->addDays((int) config('inmobiliaria.inmuebles.media.ttl_dias', 30)),
            ]);
        } catch (Throwable $e) {
            Log::warning('Inmobiliaria: no se pudo cachear el media en el proveedor; se enviará por URL firmada.', [
                'media_id' => $this->mediaId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
