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
use Webkul\Inmobiliaria\Models\Inmueble;
use Webkul\Inmobiliaria\Repositories\InmuebleMediaRepository;

/**
 * Galería de un inmueble.
 *
 * Sin tope de archivos: el asesor sube cuantas imágenes quiera. El tope vive
 * del otro lado, en el envío por WhatsApp (`media_cantidad_max`), para no
 * inundar el chat del cliente — cargar y enviar son dos límites distintos.
 *
 * Los archivos van al disco privado, no a `storage/app/public`: la galería no
 * debe ser visible para cualquiera que adivine la URL (riesgo R5).
 *
 * Nota sobre las firmas: el parámetro de ruta `{inmueble}` llega ya resuelto
 * como modelo, no como id. Concord registra un route binding por cada modelo
 * que un módulo declara, así que el nombre del parámetro alcanza para que
 * Laravel inyecte la instancia — no hace falta type-hint para activarlo, pero
 * sí para no recibir un modelo donde se declaró un int.
 */
class InmuebleMediaController extends Controller
{
    protected const DISCO = 'local';

    public function __construct(protected InmuebleMediaRepository $medias) {}

    public function store(Request $request, Inmueble $inmueble): RedirectResponse
    {
        /*
        | Los límites van por tipo: una foto de 100 MB es un error de carga, pero
        | un video de 100 MB es normal. Un solo tope obligaría a elegir entre
        | rechazar videos legítimos o aceptar fotos sin comprimir.
        */
        $reglasPorTipo = [
            'foto'  => 'mimes:jpg,jpeg,png,webp|max:15360',   //  15 MB
            'plano' => 'mimes:jpg,jpeg,png,webp,pdf|max:25600', //  25 MB
            'video' => 'mimes:mp4,mov,webm|max:204800',       // 200 MB
            'tour'  => '',
        ];

        $tipo = $request->input('tipo');

        $datos = $request->validate([
            'tipo'       => 'required|in:foto,video,plano,tour',
            'archivos'   => 'required_without:url|array|max:20',
            'archivos.*' => trim('file|'.($reglasPorTipo[$tipo] ?? 'max:15360'), '|'),
            'url'        => 'required_if:tipo,tour|nullable|url|max:500',
            'caption'    => 'nullable|string|max:200',
        ], [
            'archivos.max'     => 'Máximo 20 archivos por vez. Subilos en tandas.',
            'archivos.*.max'   => 'Algún archivo supera el tamaño permitido para ese tipo.',
            'archivos.*.mimes' => 'Formato no admitido para ese tipo de archivo.',
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

    public function portada(Inmueble $inmueble, int $media): JsonResponse
    {
        $registro = $this->medias->findOrFail($media);

        abort_if($registro->inmueble_id !== $inmueble->id, 404);

        DB::transaction(function () use ($inmueble, $registro) {
            // Una sola portada por inmueble: es la primera foto que recibe el
            // cliente y no puede haber empate.
            $this->medias->getModel()->newQuery()
                ->where('inmueble_id', $inmueble->id)
                ->update(['es_portada' => false]);

            $registro->update(['es_portada' => true]);
        });

        return response()->json(['message' => 'Portada actualizada.']);
    }

    public function orden(Request $request, Inmueble $inmueble): JsonResponse
    {
        $datos = $request->validate([
            'orden'   => 'required|array',
            'orden.*' => 'integer',
        ]);

        DB::transaction(function () use ($datos, $inmueble) {
            foreach ($datos['orden'] as $posicion => $id) {
                $this->medias->getModel()->newQuery()
                    ->where('id', $id)
                    ->where('inmueble_id', $inmueble->id)
                    ->update(['orden' => $posicion]);
            }
        });

        return response()->json(['message' => 'Orden guardado.']);
    }

    public function destroy(Inmueble $inmueble, int $media): JsonResponse
    {
        $registro = $this->medias->findOrFail($media);

        abort_if($registro->inmueble_id !== $inmueble->id, 404);

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
