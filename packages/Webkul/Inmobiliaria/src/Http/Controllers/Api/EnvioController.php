<?php

namespace Webkul\Inmobiliaria\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Inmobiliaria\Repositories\InmuebleMediaRepository;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;
use Webkul\Whatsapp\Gateways\UnsupportedCapability;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Repositories\HandoffRepository;
use Webkul\Whatsapp\Services\OutboundDelivery;

/**
 * Envío de material del inmueble al cliente.
 *
 * El agente pide "4 fotos del C21-0002"; el CRM decide cuáles, en qué orden y
 * por qué vía. Las URLs de las fotos nunca viajan por el modelo de lenguaje.
 */
class EnvioController extends Controller
{
    protected const DISCO = 'local';

    /** Cómo se llama en WhatsApp cada tipo nuestro. */
    protected const TIPOS = [
        'foto'  => 'image',
        'video' => 'video',
        'plano' => 'document',
    ];

    public function __construct(
        protected InmuebleRepository $inmuebles,
        protected InmuebleMediaRepository $medias,
        protected OutboundDelivery $delivery,
        protected HandoffRepository $handoffs,
    ) {}

    public function media(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate([
            'codigo'   => 'required|string',
            'tipo'     => 'required|in:foto,video,plano,tour',
            'cantidad' => 'nullable|integer|min:1',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        if ($bloqueo = $this->delivery->bloqueoPara($conversation)) {
            return response()->json(['message' => $bloqueo[1]], $bloqueo[0]);
        }

        $inmueble = $this->inmuebles->porCodigo($datos['codigo']);

        if (! $inmueble) {
            return response()->json(['message' => "No existe un inmueble disponible con el código {$datos['codigo']}."], 404);
        }

        $archivos = $this->medias->paraEnviar($inmueble->id, $datos['tipo'], $datos['cantidad'] ?? null);

        if ($archivos->isEmpty()) {
            // 404 y no 200 con cero enviados: el agente le dijo al cliente "te
            // mando las fotos" y necesita saber que no salió ninguna para poder
            // corregirse.
            $plural = ['foto' => 'fotos', 'video' => 'videos', 'plano' => 'planos', 'tour' => 'tours virtuales'][$datos['tipo']];

            return response()->json(['message' => "El inmueble {$datos['codigo']} no tiene {$plural} cargadas en el catálogo."], 404);
        }

        try {
            $enviados = $this->despachar($conversation, $inmueble, $archivos, $datos['tipo']);
        } catch (UnsupportedCapability $e) {
            // 501: el canal activo no puede. No es un error del agente ni algo
            // que reintentar.
            return response()->json(['message' => $e->getMessage()], 501);
        }

        return response()->json([
            'message'  => 'Enviado.',
            'enviados' => $enviados,
            'codigo'   => $inmueble->codigo,
        ]);
    }

    /**
     * Manda la ubicación exacta. Gateado por handoff.
     *
     * Las coordenadas se leen de la base y van directo al gateway: nunca están
     * en una respuesta que el agente pueda leer. Lo único que el agente sabe es
     * si el envío salió o no.
     */
    public function ubicacion(Request $request, string $id): JsonResponse
    {
        $datos = $request->validate(['codigo' => 'required|string']);

        $conversation = ConversationProxy::modelClass()::findOrFail($id);

        $inmueble = $this->inmuebles->porCodigo($datos['codigo']);

        if (! $inmueble) {
            return response()->json(['message' => "No existe un inmueble disponible con el código {$datos['codigo']}."], 404);
        }

        /*
        | La condición para revelar la dirección: tiene que haber un handoff
        | abierto sobre ESTE inmueble. Sin agenda todavía (v1), "visita
        | confirmada" equivale a "el asesor titular ya tomó la conversación".
        |
        | Ojo con el orden: este chequeo va ANTES del bloqueo general, porque acá
        | el handoff abierto es un requisito, no un impedimento. Es el único
        | envío que el CRM hace estando la conversación derivada.
        */
        $handoff = $this->handoffs->abiertoDe($conversation->id);

        if (! $handoff || $handoff->referencia_codigo !== $inmueble->codigo) {
            return response()->json([
                'message' => 'La ubicación exacta solo se envía después de derivar la conversación al asesor titular de ese inmueble.',
            ], 409);
        }

        if (blank($inmueble->latitud) || blank($inmueble->longitud)) {
            return response()->json(['message' => "El inmueble {$inmueble->codigo} no tiene coordenadas cargadas."], 404);
        }

        try {
            $this->delivery->ubicacion(
                $conversation,
                (float) $inmueble->latitud,
                (float) $inmueble->longitud,
                $inmueble->titulo,
                $inmueble->direccion_exacta,
            );
        } catch (UnsupportedCapability $e) {
            return response()->json(['message' => $e->getMessage()], 501);
        }

        return response()->json(['message' => 'Ubicación enviada.', 'codigo' => $inmueble->codigo]);
    }

    /**
     * Sirve un archivo a Meta cuando todavía no está cacheado de su lado.
     *
     * La firma caduca en minutos: alcanza para que Meta lo descargue y deja de
     * servir para cualquier otra cosa.
     */
    public function archivoFirmado(int $media): StreamedResponse
    {
        $registro = $this->medias->findOrFail($media);

        abort_if(blank($registro->path) || ! Storage::disk(self::DISCO)->exists($registro->path), 404);

        return Storage::disk(self::DISCO)->response($registro->path);
    }

    /**
     * Encola cada archivo, eligiendo media_id cacheado o URL firmada.
     */
    protected function despachar($conversation, $inmueble, $archivos, string $tipo): int
    {
        // Un tour no es un archivo sino un enlace: va como texto, y WhatsApp
        // arma la previsualización.
        if ($tipo === 'tour') {
            foreach ($archivos as $archivo) {
                $this->delivery->texto($conversation, "{$inmueble->titulo}\nTour virtual: {$archivo->url}");
            }

            return $archivos->count();
        }

        $kind = self::TIPOS[$tipo];
        $ttl = (int) config('inmobiliaria.inmuebles.media.url_firmada_ttl', 15);
        $enviados = 0;

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
                /*
                | Sin caption, a pedido del equipo del agente.
                |
                | El agente manda su propia descripción como texto aparte
                | después de las fotos. Con un caption acá el cliente veía dos
                | descripciones —una corta pegada a la primera foto y la general
                | abajo— y quedaba un sándwich "descripción, fotos, descripción".
                |
                | Aplica solo a este endpoint. El envío desde el inbox
                | (GaleriaInboxController) sí lo conserva: ahí no hay agente que
                | mande una descripción después, y una foto sola sin contexto no
                | le dice nada al cliente.
                */
                null,
                $archivo->caption ?? basename((string) $archivo->path),
            );

            $enviados++;
        }

        return $enviados;
    }
}
