<?php

namespace Webkul\Inmobiliaria\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Webkul\Inmobiliaria\DataGrids\InmuebleDataGrid;
use Webkul\Inmobiliaria\Http\Requests\InmuebleRequest;
use Webkul\Inmobiliaria\Jobs\SubirMediaAlProveedor;
use Webkul\Inmobiliaria\Repositories\InmuebleMediaRepository;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;
use Webkul\User\Repositories\UserRepository;

class InmuebleController extends Controller
{
    public function __construct(
        protected InmuebleRepository $inmuebles,
        protected UserRepository $usuarios,
        protected InmuebleMediaRepository $medias,
    ) {}

    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(InmuebleDataGrid::class)->process();
        }

        return view('inmobiliaria::inmuebles.index');
    }

    public function create(): View
    {
        return view('inmobiliaria::inmuebles.create', [
            'inmueble' => null,
            'asesores' => $this->asesores(),
            // Solo una propuesta: el asesor la puede pisar con el código del
            // anuncio ya publicado (D9).
            'codigo'   => $this->inmuebles->siguienteCodigo(),
        ]);
    }

    public function store(InmuebleRequest $request): RedirectResponse
    {
        $inmueble = DB::transaction(
            fn () => $this->inmuebles->create($this->datos($request))
        );

        $fotos = $this->guardarFotosIniciales($request, $inmueble);

        session()->flash('success', $fotos
            ? "Inmueble {$inmueble->codigo} creado con {$fotos} foto(s)."
            : "Inmueble {$inmueble->codigo} creado.");

        return redirect()->route('admin.inmuebles.index');
    }

    public function edit(int $id): View
    {
        $inmueble = $this->inmuebles->with('medias')->findOrFail($id);

        return view('inmobiliaria::inmuebles.edit', [
            'inmueble' => $inmueble,
            'asesores' => $this->asesores(),
            'codigo'   => $inmueble->codigo,
        ]);
    }

    public function update(InmuebleRequest $request, int $id): RedirectResponse
    {
        DB::transaction(fn () => $this->inmuebles->update($this->datos($request), $id));

        session()->flash('success', 'Inmueble actualizado.');

        return redirect()->route('admin.inmuebles.index');
    }

    public function destroy(int $id): JsonResponse
    {
        $this->inmuebles->findOrFail($id);

        $this->inmuebles->delete($id);

        return response()->json(['message' => 'Inmueble eliminado.']);
    }

    /**
     * Adjunta las fotos que vinieron en el formulario de creación.
     *
     * La galería completa vive en la edición porque reordenar y elegir portada
     * necesitan un inmueble ya creado. Pero pedir que guarden y vuelvan a entrar
     * solo para subir la primera foto hacía que los inmuebles quedaran sin
     * imágenes — y sin imágenes el agente no los puede mostrar, que es el punto
     * de cargarlos.
     *
     * Fuera de la transacción del inmueble: una foto que falla no debe deshacer
     * la ficha entera. Peor caso, el inmueble queda creado sin esa foto y se
     * sube desde la edición.
     */
    protected function guardarFotosIniciales(InmuebleRequest $request, $inmueble): int
    {
        $archivos = $request->file('fotos', []);

        if (! $archivos) {
            return 0;
        }

        $n = 0;

        foreach (array_slice($archivos, 0, 20) as $archivo) {
            if (! str_starts_with((string) $archivo->getMimeType(), 'image/')) {
                continue;
            }

            $media = $this->medias->create([
                'inmueble_id' => $inmueble->id,
                'tipo'        => 'foto',
                'path'        => $archivo->store("inmuebles/{$inmueble->id}", 'local'),
                'orden'       => ++$n,
                // La primera que se sube queda de portada: es mejor que ninguna,
                // y se cambia arrastrando desde la edición.
                'es_portada'  => $n === 1,
            ]);

            SubirMediaAlProveedor::dispatch($media->id)->onQueue(config('whatsapp.queue'));
        }

        return $n;
    }

    /**
     * Normaliza lo que manda el formulario antes de guardarlo.
     *
     * @return array<string, mixed>
     */
    protected function datos(InmuebleRequest $request): array
    {
        $datos = $request->validated();

        $datos['caracteristicas'] = $this->caracteristicas($datos['caracteristicas'] ?? []);

        // Un inmueble que pasa a disponible y nunca se publicó, se marca ahora:
        // es la fecha desde la que el agente lo puede ofrecer.
        if ($datos['estado'] === 'disponible' && blank($request->input('publicado_at'))) {
            $datos['publicado_at'] = now();
        }

        return $datos;
    }

    /**
     * Convierte los pares clave/valor del formulario en el JSON de la columna.
     *
     * Una fila sin nombre se descarta: el formulario siempre deja una vacía al
     * final para poder agregar otra, y borrar una fila es justamente vaciarla.
     * Eso hace que quitar una característica funcione sin ningún borrado
     * explícito — lo que no llega, no se guarda.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, string>|null
     */
    protected function caracteristicas(array $entrada): ?array
    {
        $claves = $entrada['clave'] ?? [];
        $valores = $entrada['valor'] ?? [];

        $resultado = [];

        foreach ($claves as $i => $clave) {
            $clave = trim((string) $clave);
            $valor = trim((string) ($valores[$i] ?? ''));

            if ($clave === '' || $valor === '') {
                continue;
            }

            $resultado[$clave] = $valor;
        }

        return $resultado ?: null;
    }

    /**
     * Solo usuarios con rol Asesor pueden ser titulares.
     */
    protected function asesores()
    {
        return $this->usuarios->getModel()->newQuery()
            ->whereHas('role', fn ($q) => $q->where('name', 'Asesor'))
            ->orderBy('name')
            ->get();
    }
}
