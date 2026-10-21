<?php

namespace Webkul\Inmobiliaria\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
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

        /*
        | Idempotencia: la misma galería pedida dos veces seguidas sale una vez.
        |
        | MEDIDO EN PRODUCCIÓN: las mismas 4 imágenes salieron dos veces con 19
        | segundos de diferencia. Son dos peticiones reales al endpoint, no un
        | duplicado nuestro —los archivos se eligen con un `limit` y el job no
        | reintentó—. Es la firma de un reintento por timeout: enviar diez fotos
        | a Meta no es instantáneo, el cliente HTTP del agente corta y reintenta,
        | y el primer envío ya había salido.
        |
        | El reintento del agente es correcto; lo que faltaba era que este
        | endpoint supiera que dos pedidos idénticos seguidos son la misma
        | intención. Se responde 200 con lo ya enviado: el agente ve éxito y el
        | cliente recibe una sola galería.
        |
        | La marca se pone ANTES de despachar y no después: si se pusiera al
        | final, el reintento entraría durante el despacho —que es justo cuando
        | llega— y encontraría la puerta abierta.
        */
        $huella = $this->huellaDeEnvio($conversation->id, $datos);

        if ($yaEnviado = Cache::get($huella)) {
            return response()->json([
                'message'  => 'Enviado.',
                'enviados' => $yaEnviado,
                'codigo'   => $inmueble->codigo,
                // Para que el agente pueda distinguirlo en sus logs si quiere;
                // no necesita hacer nada distinto.
                'repetido' => true,
            ]);
        }

        $archivos = $this->medias->paraEnviar($inmueble->id, $datos['tipo'], $datos['cantidad'] ?? null);

        if ($archivos->isEmpty()) {
            // 404 y no 200 con cero enviados: el agente le dijo al cliente "te
            // mando las fotos" y necesita saber que no salió ninguna para poder
            // corregirse.
            $plural = ['foto' => 'fotos', 'video' => 'videos', 'plano' => 'planos', 'tour' => 'tours virtuales'][$datos['tipo']];

            return response()->json(['message' => "El inmueble {$datos['codigo']} no tiene {$plural} cargadas en el catálogo."], 404);
        }

        /*
        | No repetirle al cliente una foto que ya recibió en esta conversación.
        |
        | MEDIDO: C21-0005 y C21-0006 son la misma propiedad cargada dos veces en
        | el catálogo, y sus diez fotos son byte por byte idénticas (mismo md5,
        | mismo tamaño). El agente ofreció las dos y el cliente recibió cada foto
        | dos veces.
        |
        | Se deduplica por CONTENIDO y no por id de archivo ni por
        | `provider_media_id`: los registros son distintos —son subidas separadas—
        | y el id de Meta cambia en cada subida. Lo único estable es el hash.
        |
        | Esto también cubre el caso de un asesor que sube la misma foto dos
        | veces al mismo inmueble, que hoy no pasa pero va a pasar.
        */
        $archivos = $this->sinRepetirEnLaConversacion($conversation->id, $archivos);

        if ($archivos->isEmpty()) {
            return response()->json([
                'message'  => 'Enviado.',
                'enviados' => 0,
                'codigo'   => $inmueble->codigo,
                // El agente necesita saber que no salió nada para no decirle al
                // cliente «ahí le mandé las fotos» cuando ya las tenía.
                'repetido' => true,
                'motivo'   => 'El cliente ya recibió estas fotos en esta conversación.',
            ]);
        }

        /*
         * Se marca con la cantidad REAL de archivos, no con la pedida: así el
         * reintento responde lo mismo que respondió el envío original.
         */
        Cache::put($huella, $archivos->count(), $this->ventanaIdempotencia());

        try {
            $enviados = $this->despachar($conversation, $inmueble, $archivos, $datos['tipo']);
        } catch (UnsupportedCapability $e) {
            /*
             * No salió nada, así que se levanta la marca: un reintento tiene que
             * poder intentarlo de nuevo. Dejarla puesta convertiría un fallo
             * total en un «ya enviado» mentiroso durante un minuto.
             */
            Cache::forget($huella);

            // 501: el canal activo no puede. No es un error del agente ni algo
            // que reintentar.
            return response()->json(['message' => $e->getMessage()], 501);
        } catch (\Throwable $e) {
            Cache::forget($huella);

            throw $e;
        }

        /*
         * Después de despachar, no antes: si el envío falla, el cliente no
         * recibió nada y tiene que poder recibirlo en el próximo intento.
         */
        $this->anotarEnviados($conversation->id, $archivos);

        return response()->json([
            'message'  => 'Enviado.',
            'enviados' => $enviados,
            'codigo'   => $inmueble->codigo,
        ]);
    }

    /**
     * Quita los archivos cuyo CONTENIDO ya se le envió a esta conversación.
     *
     * El hash de cada archivo se calcula una sola vez en la vida y queda
     * cacheado por id de media: leer diez archivos de disco en cada envío sería
     * caro, y el contenido de un archivo subido no cambia.
     *
     * Se registra lo enviado recién después de despachar —en `despachar()`— para
     * que un fallo no deje al cliente sin fotos y marcadas como enviadas.
     *
     * @param  \Illuminate\Support\Collection  $archivos
     * @return \Illuminate\Support\Collection
     */
    protected function sinRepetirEnLaConversacion(int $conversationId, $archivos)
    {
        if (! $this->ventanaDeduplicacion()) {
            return $archivos;
        }

        $yaEnviados = (array) Cache::get($this->claveEnviados($conversationId), []);

        return $archivos->reject(function ($archivo) use ($yaEnviados) {
            $hash = $this->hashDe($archivo);

            return $hash && in_array($hash, $yaEnviados, true);
        })->values();
    }

    /**
     * Anota el contenido ya enviado, para no repetirlo.
     *
     * @param  \Illuminate\Support\Collection  $archivos
     */
    protected function anotarEnviados(int $conversationId, $archivos): void
    {
        if (! ($ventana = $this->ventanaDeduplicacion())) {
            return;
        }

        $clave = $this->claveEnviados($conversationId);

        $hashes = array_values(array_unique(array_merge(
            (array) Cache::get($clave, []),
            $archivos->map(fn ($a) => $this->hashDe($a))->filter()->all(),
        )));

        Cache::put($clave, $hashes, $ventana);
    }

    /**
     * El md5 del archivo, calculado una vez y cacheado por id de media.
     *
     * Devuelve null si el archivo no está en disco: en ese caso no se deduplica
     * —mejor mandar algo dos veces que no mandarlo— y el envío sigue su curso.
     */
    protected function hashDe($archivo): ?string
    {
        return Cache::rememberForever(
            "inmo:media-hash:{$archivo->id}",
            function () use ($archivo) {
                $ruta = Storage::disk('local')->path($archivo->path);

                return is_file($ruta) ? md5_file($ruta) : null;
            }
        );
    }

    protected function claveEnviados(int $conversationId): string
    {
        return "inmo:media-enviada:{$conversationId}";
    }

    /**
     * Cuánto tiempo se recuerda lo que ya se le mandó al cliente.
     *
     * Veinticuatro horas: cubre una conversación completa sin recordar para
     * siempre, así que si el cliente vuelve en una semana y pide las fotos de
     * nuevo, se las manda.
     */
    protected function ventanaDeduplicacion(): int
    {
        return (int) config('inmobiliaria.inmuebles.media.dedup_minutos', 1440) * 60;
    }

    /**
     * La huella de un pedido de media, para detectar el reintento.
     *
     * Entran conversación, código, tipo y cantidad: pedir las fotos del mismo
     * inmueble en la misma conversación es el caso a deduplicar, pero pedir los
     * planos del mismo inmueble, o las fotos de otro, son pedidos distintos y
     * tienen que pasar.
     *
     * @param  array<string, mixed>  $datos
     */
    protected function huellaDeEnvio(int $conversationId, array $datos): string
    {
        return 'inmo:envio-media:'.implode(':', [
            $conversationId,
            $datos['codigo'],
            $datos['tipo'],
            $datos['cantidad'] ?? 'default',
        ]);
    }

    /**
     * Cuántos segundos dos pedidos idénticos cuentan como el mismo.
     *
     * Sesenta por defecto: cubre con margen el reintento por timeout —el caso
     * medido fueron 19 segundos— y no estorba al asesor que quiera reenviar la
     * galería a propósito un minuto después.
     */
    protected function ventanaIdempotencia(): int
    {
        return (int) config('inmobiliaria.inmuebles.media.idempotencia_segundos', 60);
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
