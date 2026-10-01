<?php

namespace Webkul\Admin\Http\Controllers\Products;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductMediaRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Support\Caracteristicas;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\OutboundAttachment;

/**
 * Enviar la ficha de un producto al cliente desde el inbox.
 *
 * Vive en Admin y NO en Webkul/Whatsapp a propósito. Ese módulo lo comparte el
 * otro tenant (c21_laravel), que no tiene catálogo de productos de imprenta y
 * no debe saber que existen: el módulo de dominio conoce a WhatsApp, WhatsApp
 * no conoce al dominio. Meterlo al revés obligaría a resolver a mano cada merge
 * futuro entre los dos proyectos.
 *
 * Es la contraparte de `GaleriaInboxController` del proyecto c21.
 */
class ProductoInboxController extends Controller
{
    /*
    | Cómo viaja cada tipo por WhatsApp. Un enlace no es un archivo: va como
    | texto, que además es lo único que hace que WhatsApp arme el preview.
    */
    protected const TIPOS = [
        'imagen'    => 'image',
        'documento' => 'document',
    ];

    /**
     * Cuántos adjuntos como máximo en un envío.
     *
     * No es el límite de carga (20 por tanda): es cuántos aguanta el chat del
     * cliente sin volverse ilegible. Cargar y enviar son dos límites distintos.
     */
    protected const MAX_ENVIO = 10;

    public function __construct(
        protected ProductRepository $products,
        protected ProductMediaRepository $medias,
    ) {}

    /**
     * Buscar productos con adjuntos, para el selector del inbox.
     */
    public function buscar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'q' => 'nullable|string|max:120',
        ]);

        $termino = trim($datos['q'] ?? '');

        $query = $this->products->getModel()->newQuery()
            ->with('medias')
            ->has('medias')
            ->limit(20);

        if ($termino !== '') {
            $query->where(function ($q) use ($termino) {
                $q->where('name', 'like', "%{$termino}%")
                    ->orWhere('sku', 'like', "%{$termino}%");
            });
        }

        return response()->json([
            'data' => $query->get()->map(fn ($producto) => [
                'id'         => $producto->id,
                'sku'        => $producto->sku,
                'name'       => $producto->name,
                'price'      => $producto->price,
                'adjuntos'   => $producto->medias->map(fn ($m) => [
                    'id'         => $m->id,
                    'tipo'       => $m->tipo,
                    'es_portada' => $m->es_portada,
                    'enlace'     => $m->enlace,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Enviar los adjuntos elegidos de un producto a una conversación.
     */
    public function enviar(Request $request, OutboundAttachment $attachments): JsonResponse
    {
        $datos = $request->validate([
            'conversation_id' => 'required|integer',
            'product_id'      => 'required|integer',
            'media_ids'       => 'required|array|max:'.self::MAX_ENVIO,
            'media_ids.*'     => 'integer',
            'incluir_ficha'   => 'nullable|boolean',
        ], [
            'media_ids.max' => 'Como máximo '.self::MAX_ENVIO.' archivos por envío, para no saturar el chat.',
        ]);

        $conversation = ConversationProxy::modelClass()::findOrFail($datos['conversation_id']);

        // Misma defensa que el composer del inbox: un humano nunca escribe
        // encima del agente.
        if ($conversation->aiEffective()) {
            return response()->json([
                'message' => 'El agente está activo en esta conversación. Desactivalo para enviar la ficha.',
            ], 422);
        }

        $product = $this->products->findOrFail($datos['product_id']);

        $adjuntos = $product->medias()->whereIn('id', $datos['media_ids'])->get();

        if ($adjuntos->isEmpty()) {
            return response()->json(['message' => 'No se encontró ninguno de esos adjuntos en el producto.'], 422);
        }

        // Se valida TODO antes de encolar nada: un rechazo a mitad de camino
        // dejaría al cliente con media ficha y sin forma de saber qué faltó.
        foreach ($adjuntos as $media) {
            if (! $media->esArchivo()) {
                continue;
            }

            if ($error = $this->rechazoPorLimite($media)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $mensajes = DB::transaction(function () use ($conversation, $product, $adjuntos, $attachments, $datos) {
            $creados = [];

            if ($datos['incluir_ficha'] ?? false) {
                $creados[] = $this->mensajeTexto($conversation, $this->ficha($product));
            }

            foreach ($adjuntos as $media) {
                $creados[] = $media->esArchivo()
                    ? $this->mensajeArchivo($conversation, $media, $attachments)
                    : $this->mensajeTexto($conversation, $media->url);
            }

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $creados;
        });

        // Encadenados, no despachados en paralelo: el orden de la galería es el
        // orden en que el cliente los tiene que ver, y workers concurrentes no
        // garantizan ninguno.
        Bus::chain(array_map(fn ($m) => new SendMessage($m->id), $mensajes))
            ->onQueue(config('whatsapp.queue'))
            ->dispatch();

        return response()->json(['enviados' => count($mensajes)]);
    }

    /**
     * Los límites de envío son los de Meta, no los de carga.
     *
     * Un PDF de 25 MB entra al CRM sin problema pero no siempre sale por
     * WhatsApp. Rechazarlo acá convierte un Graph 400 opaco en un error que el
     * asesor entiende.
     */
    protected function rechazoPorLimite($media): ?string
    {
        $tipo = self::TIPOS[$media->tipo];

        $limites = (array) config('whatsapp.media.'.$tipo);

        if (! Storage::disk('local')->exists($media->path)) {
            return 'Un archivo de tipo '.$media->tipo.' ya no está en el servidor.';
        }

        $kb = Storage::disk('local')->size($media->path) / 1024;

        if (isset($limites['max_kb']) && $kb > $limites['max_kb']) {
            return 'Un archivo supera el límite de WhatsApp para '
                .$tipo.' ('.round($limites['max_kb'] / 1024, 1).' MB).';
        }

        $mime = Storage::disk('local')->mimeType($media->path);

        if (! empty($limites['mimes']) && ! in_array($mime, $limites['mimes'], true)) {
            return 'WhatsApp no acepta el formato de alguno de los archivos como '.$tipo.'.';
        }

        return null;
    }

    /**
     * Copia el archivo del disco privado al staging público del módulo de
     * WhatsApp, que es de donde lo lee el gateway.
     */
    protected function mensajeArchivo($conversation, $media, OutboundAttachment $attachments)
    {
        $tipo = self::TIPOS[$media->tipo];

        $file = $attachments->stageContents(
            Storage::disk('local')->get($media->path),
            basename($media->path),
            Storage::disk('local')->mimeType($media->path),
            $conversation->id,
        );

        return MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => $tipo,
            'body'            => null,
            'media_path'      => $file['url'],
            'media_mime'      => $file['mime'],
            'media_name'      => $file['name'],
            'status'          => 'queued',
            'sender'          => 'human',
            'payload'         => ['media_disk_path' => $file['path']],
        ]);
    }

    protected function mensajeTexto($conversation, string $texto)
    {
        return MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'text',
            'body'            => $texto,
            'status'          => 'queued',
            'sender'          => 'human',
        ]);
    }

    /**
     * La ficha en texto: nombre, precio y características cargadas.
     *
     * Se arma acá y no en el front para que lo que ve el cliente sea lo que
     * está en la base, no lo que el navegador alcanzó a renderizar.
     */
    protected function ficha($product): string
    {
        $lineas = ['*'.$product->name.'*'];

        if ($product->sku) {
            $lineas[] = 'Código: '.$product->sku;
        }

        if ($product->price) {
            $lineas[] = 'Precio: '.core()->formatBasePrice($product->price);
        }

        $campos = Caracteristicas::paraCategorias($this->categorias($product));

        foreach ((array) $product->caracteristicas as $clave => $valor) {
            $lineas[] = Caracteristicas::etiqueta($clave, $campos).': '.$valor;
        }

        return implode("\n", $lineas);
    }

    /**
     * Las categorías del producto salen del atributo `product_type`.
     */
    protected function categorias($product): array
    {
        $valor = $product->{'product_type'} ?? null;

        return array_filter(is_array($valor) ? $valor : (array) $valor);
    }
}
