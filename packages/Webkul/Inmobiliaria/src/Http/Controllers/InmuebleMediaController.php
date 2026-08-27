<?php

namespace Webkul\Inmobiliaria\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Inmobiliaria\Jobs\SubirMediaAlProveedor;
use Webkul\Inmobiliaria\Repositories\InmuebleMediaRepository;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;

/**
 * Galería de un inmueble.
 *
 * Sin tope de archivos: el asesor sube cuantas imágenes quiera. El tope vive
 * del otro lado, en el envío por WhatsApp (`media_cantidad_max`), para no
 * inundar el chat del cliente — cargar y enviar son dos límites distintos.
 *
 * Los archivos van al disco privado, no a `storage/app/public`: la galería no
 * debe ser visible para cualquiera que adivine la URL (riesgo R5).
 */
class InmuebleMediaController extends Controller
{
    protected const DISCO = 'local';

    public function __construct(
        protected InmuebleRepository $inmuebles,
        protected InmuebleMediaRepository $medias,
    ) {}

    public function store(Request $request, int $inmueble): RedirectResponse
    {
        $inmueble = $this->inmuebles->findOrFail($inmueble);

        $datos = $request->validate([
            'tipo'        => 'required|in:foto,video,plano,tour',
            'archivos'    => 'required_without:url|array',
            'archivos.*'  => 'file|max:20480|mimes:jpg,jpeg,png,webp,mp4,pdf',
            'url'         => 'required_if:tipo,tour|nullable|url|max:500',
            'caption'     => 'nullable|string|max:200',
        ], [
            'archivos.*.max'   => 'Cada archivo debe pesar menos de 20 MB.',
            'url.required_if'  => 'Un tour necesita la URL del recorrido virtual.',
        ]);

        // El orden arranca donde terminó lo ya cargado, para que subir en
        // tandas no reordene la galería existente.
        $orden = (int) $this->medias->getModel()->newQuery()
            ->where('inmueble_id', $inmueble->id)
            ->where('tipo', $datos['tipo'])
            ->max('orden');

        DB::transaction(function () use ($request, $inmueble, $datos, &$orden) {
            // Un tour es una URL externa, no un archivo.
            if ($datos['tipo'] === 'tour') {
                $this->crear($inmueble->id, 'tour', null, $datos['url'], $datos['caption'] ?? null, ++$orden);

                return;
            }

            foreach ($request->file('archivos', []) as $archivo) {
                $path = $archivo->store("inmuebles/{$inmueble->id}", self::DISCO);

                $this->crear($inmueble->id, $datos['tipo'], $path, null, $datos['caption'] ?? null, ++$orden);
            }
        });

        session()->flash('success', 'Archivos cargados.');

        return back();
    }

    public function portada(int $inmueble, int $media): JsonResponse
    {
        $registro = $this->medias->findOrFail($media);

        abort_if($registro->inmueble_id !== $inmueble, 404);

        DB::transaction(function () use ($inmueble, $registro) {
            // Una sola portada por inmueble: es la primera foto que recibe el
            // cliente y no puede haber empate.
            $this->medias->getModel()->newQuery()
                ->where('inmueble_id', $inmueble)
                ->update(['es_portada' => false]);

            $registro->update(['es_portada' => true]);
        });

        return response()->json(['message' => 'Portada actualizada.']);
    }

    public function orden(Request $request, int $inmueble): JsonResponse
    {
        $datos = $request->validate([
            'orden'   => 'required|array',
            'orden.*' => 'integer',
        ]);

        DB::transaction(function () use ($datos, $inmueble) {
            foreach ($datos['orden'] as $posicion => $id) {
                $this->medias->getModel()->newQuery()
                    ->where('id', $id)
                    ->where('inmueble_id', $inmueble)
                    ->update(['orden' => $posicion]);
            }
        });

        return response()->json(['message' => 'Orden guardado.']);
    }

    public function destroy(int $inmueble, int $media): JsonResponse
    {
        $registro = $this->medias->findOrFail($media);

        abort_if($registro->inmueble_id !== $inmueble, 404);

        if ($registro->path && Storage::disk(self::DISCO)->exists($registro->path)) {
            Storage::disk(self::DISCO)->delete($registro->path);
        }

        $registro->delete();

        return response()->json(['message' => 'Archivo eliminado.']);
    }

    /**
     * Sirve el archivo a un usuario autenticado del CRM.
     *
     * La ruta vive bajo el admin path con el middleware `user`, así que un
     * visitante anónimo no llega. Para el cliente final el archivo viaja por
     * WhatsApp, nunca por este endpoint.
     */
    public function archivo(int $media): StreamedResponse
    {
        $registro = $this->medias->findOrFail($media);

        abort_if(blank($registro->path) || ! Storage::disk(self::DISCO)->exists($registro->path), 404);

        return Storage::disk(self::DISCO)->response($registro->path);
    }

    protected function crear(int $inmuebleId, string $tipo, ?string $path, ?string $url, ?string $caption, int $orden): void
    {
        $media = $this->medias->create([
            'inmueble_id' => $inmuebleId,
            'tipo'        => $tipo,
            'path'        => $path,
            'url'         => $url,
            'caption'     => $caption,
            'orden'       => $orden,
            // La primera foto que se sube es la portada por defecto: es mejor
            // que no haya ninguna y que el envío salga en orden arbitrario.
            'es_portada'  => $tipo === 'foto' && ! $this->medias->getModel()->newQuery()
                ->where('inmueble_id', $inmuebleId)
                ->where('es_portada', true)
                ->exists(),
        ]);

        // Se sube al proveedor ahora, no en el momento del envío (D10).
        if ($path) {
            SubirMediaAlProveedor::dispatch($media->id)->onQueue(config('whatsapp.queue'));
        }
    }
}
