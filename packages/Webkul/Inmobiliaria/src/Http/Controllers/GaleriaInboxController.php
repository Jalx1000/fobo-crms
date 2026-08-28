<?php

namespace Webkul\Inmobiliaria\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;
use Webkul\Inmobiliaria\Repositories\InmuebleMediaRepository;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;
use Webkul\Whatsapp\Gateways\UnsupportedCapability;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Services\OutboundDelivery;

/**
 * Enviar la galería de un inmueble desde el inbox, como asesor.
 *
 * Existe porque el caso real apareció al revés de lo previsto: el agente derivó
 * la conversación, el cliente siguió pidiendo fotos, y el asesor que la tomó no
 * tenía forma de mandárselas — el CRM sabía hacerlo por API pero no había botón.
 *
 * A diferencia del endpoint que usa el agente, este NO se bloquea con el handoff
 * abierto: acá el humano es justamente quien tomó la conversación.
 */
class GaleriaInboxController extends Controller
{
    protected const TIPOS = [
        'foto'  => 'image',
        'video' => 'video',
        'plano' => 'document',
    ];

    public function __construct(
        protected InmuebleRepository $inmuebles,
        protected InmuebleMediaRepository $medias,
        protected OutboundDelivery $delivery,
    ) {}

    public function enviar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'conversation_id' => 'required|integer',
            'codigo'          => 'required|string|max:32',
            'tipo'            => 'nullable|in:foto,video,plano,tour',
            'cantidad'        => 'nullable|integer|min:1',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($datos['conversation_id']);
        $tipo = $datos['tipo'] ?? 'foto';

        // El inmueble se busca sin exigir que esté disponible: el asesor puede
        // querer mostrar algo reservado para explicar por qué no lo ofrece.
        $inmueble = $this->inmuebles->porCodigo($datos['codigo'], soloOfrecibles: false);

        if (! $inmueble) {
            return response()->json(['message' => "No existe el inmueble {$datos['codigo']}."], 404);
        }

        $archivos = $this->medias->paraEnviar($inmueble->id, $tipo, $datos['cantidad'] ?? null);

        if ($archivos->isEmpty()) {
            $plural = ['foto' => 'fotos', 'video' => 'videos', 'plano' => 'planos', 'tour' => 'tours'][$tipo];

            return response()->json([
                'message' => "El inmueble {$inmueble->codigo} no tiene {$plural} cargadas en el catálogo.",
            ], 404);
        }

        try {
            $enviados = $this->despachar($conversation, $inmueble, $archivos, $tipo);
        } catch (UnsupportedCapability $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'  => "Enviando {$enviados} archivos de {$inmueble->codigo}.",
            'enviados' => $enviados,
        ]);
    }

    protected function despachar($conversation, $inmueble, $archivos, string $tipo): int
    {
        if ($tipo === 'tour') {
            foreach ($archivos as $archivo) {
                $this->delivery->texto($conversation, "{$inmueble->titulo}\nTour virtual: {$archivo->url}", null, 'human');
            }

            return $archivos->count();
        }

        $kind = self::TIPOS[$tipo];
        $ttl = (int) config('inmobiliaria.inmuebles.media.url_firmada_ttl', 15);
        $n = 0;

        foreach ($archivos as $archivo) {
            $payload = $archivo->mediaIdVigente()
                ? ['provider_media_id' => $archivo->provider_media_id]
                : ['url' => URL::temporarySignedRoute(
                    'inmobiliaria.media.firmada',
                    now()->addMinutes($ttl),
                    ['media' => $archivo->id],
                )];

            $this->delivery->media(
                $conversation,
                $kind,
                $payload,
                $n === 0 ? $inmueble->lineaDestacada() : null,
                $archivo->caption ?? basename((string) $archivo->path),
                'human',
            );

            $n++;
        }

        return $n;
    }
}
