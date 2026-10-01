<?php

namespace Webkul\Admin\Http\Controllers\Products;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductMediaRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Adjuntos de un producto: subida por tandas, orden, portada y borrado.
 *
 * Portado de `InmuebleMediaController` (proyecto c21).
 *
 * Los archivos van al disco privado, no a `storage/app/public`: la galería de
 * un producto no debe ser visible para cualquiera que adivine la URL. Por eso
 * se sirven por `archivo()`, detrás del middleware de sesión.
 */
class ProductMediaController extends Controller
{
    protected const DISCO = 'local';

    /*
    | Los límites van por tipo: una imagen de 25 MB es un error de carga, pero
    | un PDF de 25 MB es un pliego normal. Un solo tope obligaría a elegir entre
    | rechazar pliegos legítimos o aceptar imágenes sin comprimir.
    |
    | Son más altos que los de envío por WhatsApp a propósito: no todo lo que
    | entra al CRM se manda al cliente. El envío revalida contra
    | config('whatsapp.media') y avisa si un archivo no pasa.
    */
    protected const REGLAS = [
        'imagen'    => 'mimes:jpg,jpeg,png,webp|max:5120',      //  5 MB
        'documento' => 'mimes:pdf,jpg,jpeg,png,webp|max:25600', // 25 MB
        'enlace'    => '',
    ];

    public function __construct(
        protected ProductRepository $products,
        protected ProductMediaRepository $medias,
    ) {}

    public function store(Request $request, int $id): RedirectResponse
    {
        $product = $this->products->findOrFail($id);

        $tipo = $request->input('tipo');

        $datos = $request->validate([
            'tipo'       => 'required|in:imagen,documento,enlace',
            'archivos'   => 'required_unless:tipo,enlace|array|max:20',
            'archivos.*' => trim('file|'.(self::REGLAS[$tipo] ?? 'max:5120'), '|'),
            'url'        => 'required_if:tipo,enlace|nullable|url|max:500',
        ], [
            'archivos.max'      => 'Máximo 20 archivos por vez. Subilos en tandas.',
            'archivos.*.max'    => 'Algún archivo supera el tamaño permitido para ese tipo.',
            'archivos.*.mimes'  => 'Formato no admitido para ese tipo de archivo.',
            'url.required_if'   => 'Un enlace necesita la URL.',
        ]);

        $orden = $this->medias->ultimoOrden($product->id, $datos['tipo']);

        DB::transaction(function () use ($request, $product, $datos, &$orden) {
            if ($datos['tipo'] === 'enlace') {
                $this->crear($product->id, 'enlace', null, $datos['url'], ++$orden);

                return;
            }

            foreach ($request->file('archivos', []) as $archivo) {
                $path = $archivo->store("productos/{$product->id}", self::DISCO);

                $this->crear($product->id, $datos['tipo'], $path, null, ++$orden);
            }
        });

        session()->flash('success', 'Archivos cargados.');

        return back();
    }

    public function portada(int $id, int $media): JsonResponse
    {
        $product = $this->products->findOrFail($id);

        $registro = $this->medias->findOrFail($media);

        abort_if($registro->product_id !== $product->id, 404);

        $this->medias->marcarPortada($product->id, $media);

        return response()->json(['ok' => true]);
    }

    /**
     * Asignar el adjunto a una variante, o dejarlo general (variante vacía).
     *
     * Es lo que hace que el agente pueda mandar «la ficha de 50 L» y no las
     * cinco fichas de Magia Verde: cada PDF sabe de qué tamaño es.
     */
    public function variante(Request $request, int $id, int $media): JsonResponse
    {
        $product = $this->products->findOrFail($id);

        $registro = $this->medias->findOrFail($media);

        abort_if($registro->product_id !== $product->id, 404);

        $datos = $request->validate([
            'variante_id' => 'nullable|integer',
        ]);

        $varianteId = $datos['variante_id'] ?? null;

        // Solo una variante de ESTE producto: un id de otro producto dejaría
        // el adjunto colgado de una variante que no le corresponde.
        if ($varianteId && ! $product->variantes()->whereKey($varianteId)->exists()) {
            return response()->json(['message' => 'Esa variante no es de este producto.'], 422);
        }

        $registro->forceFill(['variante_id' => $varianteId ?: null])->save();

        return response()->json(['ok' => true, 'variante_id' => $registro->variante_id]);
    }

    /**
     * Reordenar por arrastre. Llega la lista de ids en el orden nuevo.
     */
    public function orden(Request $request, int $id): JsonResponse
    {
        $product = $this->products->findOrFail($id);

        $datos = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer',
        ]);

        DB::transaction(function () use ($datos, $product) {
            foreach ($datos['ids'] as $posicion => $mediaId) {
                $this->medias->getModel()->newQuery()
                    ->where('product_id', $product->id)
                    ->where('id', $mediaId)
                    ->update(['orden' => $posicion + 1]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(int $id, int $media): JsonResponse
    {
        $product = $this->products->findOrFail($id);

        $registro = $this->medias->findOrFail($media);

        abort_if($registro->product_id !== $product->id, 404);

        // El archivo se borra con el registro: dejarlo huérfano en el disco
        // hace crecer el storage sin que nada lo referencie.
        if ($registro->path && Storage::disk(self::DISCO)->exists($registro->path)) {
            Storage::disk(self::DISCO)->delete($registro->path);
        }

        $this->medias->delete($media);

        return response()->json(['ok' => true]);
    }

    /**
     * Servir un archivo del disco privado.
     *
     * Vive detrás del middleware `user`: sin sesión, 404.
     */
    public function archivo(int $media): StreamedResponse
    {
        $registro = $this->medias->findOrFail($media);

        abort_if(blank($registro->path) || ! Storage::disk(self::DISCO)->exists($registro->path), 404);

        return Storage::disk(self::DISCO)->response($registro->path);
    }

    protected function crear(int $productId, string $tipo, ?string $path, ?string $url, int $orden): void
    {
        $this->medias->create([
            'product_id' => $productId,
            'tipo'       => $tipo,
            'path'       => $path,
            'url'        => $url,
            'orden'      => $orden,
            // La primera imagen que entra es la portada: si nadie elige una, la
            // ficha igual tiene con qué mostrarse.
            'es_portada' => $tipo === 'imagen'
                && ! $this->medias->getModel()->newQuery()
                    ->where('product_id', $productId)
                    ->where('es_portada', true)
                    ->exists(),
        ]);
    }
}
