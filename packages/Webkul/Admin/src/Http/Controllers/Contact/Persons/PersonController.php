<?php

namespace Webkul\Admin\Http\Controllers\Contact\Persons;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;
use Prettus\Repository\Criteria\RequestCriteria;
use Webkul\Admin\DataGrids\Contact\PersonDataGrid;
use Webkul\Admin\Helpers\GlobalDateFilter;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\AttributeForm;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Resources\PersonResource;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Core\Services\Distribuidores;
use Webkul\Lead\Repositories\PipelineRepository;

class PersonController extends Controller
{
    /**
     * Create a new class instance.
     *
     * @return void
     */
    public function __construct(
        protected PersonRepository $personRepository,
        protected PipelineRepository $pipelineRepository
    ) {
        request()->request->add(['entity_type' => 'persons']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(PersonDataGrid::class)->process();
        }

        if ($redirect = $this->syncGlobalFilters()) {
            return $redirect;
        }

        return view('admin::contacts.persons.index', [
            'pipelines' => $this->pipelineRepository->all(['id', 'name']),
        ]);
    }

    /**
     * Keep the prospect list in sync with the shared global filters used by the
     * dashboard and the Pedidos/Leads module.
     *
     * City lives in the "global_pipeline_id" cookie, the date range in
     * "global_date_range" ("from|to"). We normalize both to explicit query
     * params (pipeline_id, start, end) so the DataGrid - which forwards the
     * page's query string on its AJAX request - filters accordingly.
     *
     * Returns a RedirectResponse when the URL had to be normalized, otherwise
     * null so the caller can render the view.
     */
    protected function syncGlobalFilters(): ?RedirectResponse
    {
        /**
         * City: apply the saved city when the URL carries none, otherwise
         * persist an explicit selection ('' means "Todas", i.e. no filter).
         */
        if (request()->query('pipeline_id') === null) {
            $savedPipelineId = request()->cookie('global_pipeline_id');

            if (is_numeric($savedPipelineId)) {
                return redirect()->route('admin.contacts.persons.index', array_merge(
                    request()->except('pipeline_id'),
                    ['pipeline_id' => $savedPipelineId]
                ));
            }
        } else {
            $pipelineId = request()->query('pipeline_id');

            // httpOnly=false so the dashboard's JS can read the same cookie.
            Cookie::queue('global_pipeline_id', $pipelineId === '' ? 'all' : $pipelineId, 60 * 24 * 365, '/', null, false, false, false, 'Lax');
        }

        /**
         * Date: "clear_date" forgets the shared range; otherwise apply the saved
         * range when absent, or persist the one supplied via start/end.
         */
        if (request()->query('clear_date') !== null) {
            GlobalDateFilter::forget();

            return redirect()->route('admin.contacts.persons.index', request()->except(['start', 'end', 'clear_date']));
        }

        $hasDateParam = request()->query('start') !== null
            || request()->query('end') !== null;

        if (! $hasDateParam) {
            if ($saved = GlobalDateFilter::resolve(request()->cookie(GlobalDateFilter::COOKIE))) {
                return redirect()->route('admin.contacts.persons.index', array_merge(
                    request()->all(),
                    ['start' => $saved['from'], 'end' => $saved['to']]
                ));
            }
        } else {
            $from = request()->query('start');

            $to = request()->query('end');

            if ($from && $to) {
                GlobalDateFilter::remember($from, $to);
            }
        }

        return null;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('admin::contacts.persons.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AttributeForm $request): RedirectResponse|JsonResponse
    {
        /*
         * Un distribuidor no puede abrir una segunda ficha para un cliente que ya
         * existe: el teléfono es la identidad del cliente, y dos fichas con el mismo
         * número lo dejarían repartido entre dos empresas.
         */
        if ($aviso = $this->avisoDeClienteRegistrado($request->input('contact_numbers'))) {
            if (request()->ajax()) {
                return response()->json(['message' => $aviso], 422);
            }

            session()->flash('error', $aviso);

            return redirect()->back()->withInput();
        }

        Event::dispatch('contacts.person.create.before');

        $person = $this->personRepository->create($request->all());

        Event::dispatch('contacts.person.create.after', $person);

        if (request()->ajax()) {
            return response()->json([
                'data'    => $person,
                'message' => trans('admin::app.contacts.persons.index.create-success'),
            ]);
        }

        session()->flash('success', trans('admin::app.contacts.persons.index.create-success'));

        return redirect()->route('admin.contacts.persons.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id): View
    {
        $person = $this->personRepository->findOrFail($id);

        return view('admin::contacts.persons.view', compact('person'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $person = $this->personRepository->findOrFail($id);

        return view('admin::contacts.persons.edit', compact('person'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AttributeForm $request, int $id): RedirectResponse|JsonResponse
    {
        Event::dispatch('contacts.person.update.before', $id);

        $person = $this->personRepository->update($request->all(), $id);

        Event::dispatch('contacts.person.update.after', $person);

        if (request()->ajax()) {
            return response()->json([
                'data'    => $person,
                'message' => trans('admin::app.contacts.persons.index.update-success'),
            ], 200);
        }

        session()->flash('success', trans('admin::app.contacts.persons.index.update-success'));

        return redirect()->route('admin.contacts.persons.index');
    }

    /**
     * Search person results.
     */
    public function search(): JsonResource
    {
        if ($yo = app(Distribuidores::class)->soloLoSuyo()) {
            // Un distribuidor sólo encuentra a sus clientes, aunque otro reparta en su
            // misma ciudad.
            $persons = $this->personRepository
                ->pushCriteria(app(RequestCriteria::class))
                ->findWhere(['user_id' => $yo]);
        } elseif ($userIds = bouncer()->getAuthorizedUserIds()) {
            $persons = $this->personRepository
                ->pushCriteria(app(RequestCriteria::class))
                ->findWhereIn('user_id', $userIds);
        } else {
            $persons = $this->personRepository
                ->pushCriteria(app(RequestCriteria::class))
                ->all();
        }

        return PersonResource::collection($persons);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $person = $this->personRepository->findOrFail($id);

        if (
            $person->leads
            && $person->leads->count() > 0
        ) {
            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-failed'),
            ], 400);
        }

        try {
            Event::dispatch('contacts.person.delete.before', $person);

            $person->delete();

            Event::dispatch('contacts.person.delete.after', $person);

            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-success'),
            ], 200);

        } catch (Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * Mass destroy the specified resources from storage.
     */
    public function massDestroy(MassDestroyRequest $request): JsonResponse
    {
        try {
            $persons = $this->personRepository->findWhereIn('id', $request->input('indices', []));

            $deletedCount = 0;

            $blockedCount = 0;

            foreach ($persons as $person) {
                if (
                    $person->leads
                    && $person->leads->count() > 0
                ) {
                    $blockedCount++;

                    continue;
                }

                Event::dispatch('contact.person.delete.before', $person);

                $this->personRepository->delete($person->id);

                Event::dispatch('contact.person.delete.after', $person);

                $deletedCount++;
            }

            $statusCode = 200;

            switch (true) {
                case $deletedCount > 0 && $blockedCount === 0:
                    $message = trans('admin::app.contacts.persons.index.all-delete-success');

                    break;

                case $deletedCount > 0 && $blockedCount > 0:
                    $message = trans('admin::app.contacts.persons.index.partial-delete-warning');

                    break;

                case $deletedCount === 0 && $blockedCount > 0:
                    $message = trans('admin::app.contacts.persons.index.none-delete-warning');

                    $statusCode = 400;

                    break;

                default:
                    $message = trans('admin::app.contacts.persons.index.no-selection');

                    $statusCode = 400;

                    break;
            }

            return response()->json(['message' => $message], $statusCode);
        } catch (Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.contacts.persons.index.delete-failed'),
            ], 400);
        }
    }

    /**
     * El aviso si un distribuidor intenta dar de alta un teléfono que ya es de un
     * cliente, o null si puede seguir.
     *
     * Sólo para distribuidores: un encargado sigue cargando como siempre.
     */
    protected function avisoDeClienteRegistrado($telefonos): ?string
    {
        if (! app(Distribuidores::class)->soloLoSuyo()) {
            return null;
        }

        foreach ((array) $telefonos as $telefono) {
            $numero = is_array($telefono) ? ($telefono['value'] ?? null) : $telefono;

            if (! $numero || ! ($existente = $this->personRepository->findByPhone((string) $numero))) {
                continue;
            }

            return app(Distribuidores::class)->avisoClienteDeOtro((int) $existente->id);
        }

        return null;
    }
}
