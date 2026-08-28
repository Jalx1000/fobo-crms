<?php

namespace Webkul\Inmobiliaria\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Webkul\Inmobiliaria\DataGrids\InmuebleDataGrid;
use Webkul\Inmobiliaria\Http\Requests\InmuebleRequest;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;
use Webkul\User\Repositories\UserRepository;

class InmuebleController extends Controller
{
    public function __construct(
        protected InmuebleRepository $inmuebles,
        protected UserRepository $usuarios,
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

        session()->flash('success', "Inmueble {$inmueble->codigo} creado. Ahora podés cargar las fotos.");

        // Al editar es donde vive la galería: crear y quedarse en el listado
        // obligaría a buscar el inmueble recién creado para subirle imágenes.
        return redirect()->route('admin.inmuebles.edit', $inmueble->id);
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
     * Descarta las filas sin clave: el formulario siempre manda una fila vacía
     * al final para poder agregar otra.
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
            if (blank($clave)) {
                continue;
            }

            $resultado[trim($clave)] = trim((string) ($valores[$i] ?? ''));
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
