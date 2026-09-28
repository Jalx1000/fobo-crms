<?php

namespace Webkul\Doctor\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Repositories\SpecialtyRepository;

class DoctorController extends Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Contains route related configuration
     *
     * @var array
     */
    protected $_config;

    public function __construct(
        protected DoctorRepository $doctorRepository,
        protected SpecialtyRepository $specialtyRepository
    ) {
        $this->_config = request('_config');

        if (request()->route()?->getName() != 'admin.doctor.search') {
            $this->middleware('user');
        }
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(\Webkul\Doctor\DataGrids\DoctorDataGrid::class)->process();
        }

        return view('doctor::index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $specialties = $this->specialtyRepository->all();

        return view('doctor::create', compact('specialties'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store()
    {
        $validated = request()->validate([
            'number'             => ['nullable', 'string', 'max:50'],
            'name'               => ['required', 'string', 'min:2', 'max:150', 'unique:doctors,name'],
            'email'              => ['nullable', 'email', 'max:150'],
            'title'              => ['nullable', 'string', 'max:100'],
            'unique_id'          => ['nullable', 'string', 'max:100'],
            'specialties'        => ['nullable'],
            'specialty_names'    => ['nullable', 'array'],
            'specialty_names.*'  => ['string', 'max:100'],
        ]);

        $doctor = $this->doctorRepository->create($validated);

        session()->flash('success', 'Doctor creado correctamente');

        return redirect()->route('admin.doctor.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\View\View
     */
    public function edit($id)
    {
        $doctor = $this->doctorRepository->findOrFail($id);

        $specialties = $this->specialtyRepository->all();

        return view('doctor::create', compact('doctor', 'specialties'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        $validated = request()->validate([
            'number'             => ['nullable', 'string', 'max:50'],
            'name'               => ['required', 'string', 'min:2', 'max:150', 'unique:doctors,name,'.$id],
            'email'              => ['nullable', 'email', 'max:150'],
            'title'              => ['nullable', 'string', 'max:100'],
            'unique_id'          => ['nullable', 'string', 'max:100'],
            'is_active'          => ['nullable'],
            'specialties'        => ['nullable'],
            'specialty_names'    => ['nullable', 'array'],
            'specialty_names.*'  => ['string', 'max:100'],
        ]);

        $this->doctorRepository->update($validated, $id);

        session()->flash('success', 'Doctor actualizado correctamente');

        return redirect()->route('admin.doctor.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $this->doctorRepository->delete($id);

        return response()->json([
            'message' => 'Doctor eliminado correctamente',
        ]);
    }

    /**
     * Search doctor results.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function search()
    {
        $doctors = $this->doctorRepository
            ->pushCriteria(app(RequestCriteria::class))
            ->all();

        $results = $doctors->map(function ($doctor) {
            return [
                'id'   => $doctor->id,
                'name' => $doctor->name,
            ];
        });

        return response()->json([
            'data' => $results,
        ]);
    }
}
