<?php

namespace Webkul\Doctor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Doctor\DataGrids\SpecialtyDataGrid;
use Webkul\Doctor\Repositories\SpecialtyRepository;

class SpecialtyController extends Controller
{
    public function __construct(
        protected SpecialtyRepository $specialtyRepository,
        protected ShareMeDataService $shareMeDataService
    ) {}

    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return datagrid(SpecialtyDataGrid::class)->process();
        }

        return view('doctor::specialties.index');
    }

    public function sync(): RedirectResponse
    {
        try {
            $result = $this->shareMeDataService->syncSpecialties();

            if ($result['synced'] > 0) {
                session()->flash('success', "Se han sincronizado {$result['synced']} especialidades nuevas. ({$result['existed']} ya existían)");
            } else {
                session()->flash('info', "No se encontraron especialidades nuevas para sincronizar. ({$result['existed']} ya existían)");
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Error al sincronizar con ShareMeData: '.$e->getMessage());
        }

        return redirect()->route('admin.specialties.index');
    }

    public function create(): View
    {
        return view('doctor::specialties.form');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'min:2', 'max:150', 'unique:specialties,name'],
            'description' => ['required', 'string'],
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $specialty = $this->specialtyRepository->create($validated);

        session()->flash('success', 'Especialidad creada correctamente');

        return redirect()->route('admin.specialties.index');
    }

    public function edit(int $id): View
    {
        $specialty = $this->specialtyRepository->findOrFail($id);

        return view('doctor::specialties.form', compact('specialty'));
    }

    public function update(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $specialty = $this->specialtyRepository->findOrFail($id);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'min:2', 'max:150', 'unique:specialties,name,'.$specialty->id],
            'description' => ['required', 'string'],
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $specialty = $this->specialtyRepository->update($validated, $id);

        if ($request->ajax()) {
            return response()->json(['message' => 'Especialidad actualizada correctamente']);
        }

        session()->flash('success', 'Especialidad actualizada correctamente');

        return redirect()->route('admin.specialties.index');
    }

    public function destroy(int $id): JsonResponse
    {
        $specialty = $this->specialtyRepository->findOrFail($id);

        try {
            $specialty->delete();

            return new JsonResponse([
                'message' => 'Especialidad eliminada correctamente',
            ], 200);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'message' => 'No se pudo eliminar la especialidad',
            ], 400);
        }
    }
}
