<?php

namespace Webkul\Admin\Http\Controllers\Activity;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Activity\Repositories\FileRepository;
use Webkul\Admin\DataGrids\Activity\ActivityDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Admin\Http\Resources\ActivityResource;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\PipelineRepository;

class ActivityController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected ActivityRepository $activityRepository,
        protected FileRepository $fileRepository,
        protected AttributeRepository $attributeRepository,
        protected LeadRepository $leadRepository,
        protected PipelineRepository $pipelineRepository,
        protected ShareMeDataService $shareMeDataService,
        protected PersonRepository $personRepository,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return view('admin::activities.index');
    }

    /**
     * Returns a listing of the resource.
     */
    public function get(): JsonResponse
    {
        if (request()->get('view_type') === 'calendar' && request()->get('calendar_mode') === 'doctor') {
            $view = request()->get('calendar_view', 'week'); // 'week' | 'month' | 'day'
            if ($view === 'month') {
                $cursor = request()->get('start')
                    ? Carbon::parse(request()->get('start'))->startOfMonth()
                    : Carbon::now()->startOfMonth();
                $start = $cursor->copy()->startOfMonth();
                $end = $cursor->copy()->endOfMonth();
            } elseif ($view === 'day') {
                $cursor = request()->get('start')
                    ? Carbon::parse(request()->get('start'))->startOfDay()
                    : Carbon::now()->startOfDay();
                $start = $cursor->copy()->startOfDay();
                $end = $cursor->copy()->endOfDay();
            } else {
                $start = request()->get('start')
                    ? Carbon::parse(request()->get('start'))->startOfWeek()
                    : Carbon::now()->startOfWeek();
                $end = (clone $start)->endOfWeek();
            }
            $startDate = $start->copy()->setTime(0, 0, 1)->format('Y-m-d H:i:s');
            $endDate = $end->copy()->setTime(23, 59, 59)->format('Y-m-d H:i:s');

            $doctors = DB::table('doctors')
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get();

            $prefix = DB::getTablePrefix();

            $appointments = DB::table('activities')
                ->leftJoin('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
                ->leftJoin('activity_participants', 'activities.id', '=', 'activity_participants.activity_id')
                ->leftJoin('persons as p', 'activity_participants.person_id', '=', 'p.id')
                ->select([
                    'activities.id',
                    'activities.title',
                    'activities.type',
                    'activities.is_done',
                    'activities.schedule_from as start',
                    'activities.schedule_to as end',
                    'activities.location',
                    'doctor_activities.doctor_id',
                    DB::raw('GROUP_CONCAT('.$prefix.'p.name SEPARATOR ", ") as participants'),
                ])
                ->whereBetween('activities.schedule_from', [$startDate, $endDate])
                ->whereIn('activities.type', (function () {
                    $allowed = ['call', 'meeting', 'lunch', 'time_off'];
                    $types = request()->get('activity_types');
                    if (is_array($types)) {
                        $filtered = array_values(array_intersect($types, $allowed));

                        return count($filtered) ? $filtered : $allowed;
                    }

                    return $allowed;
                })())
                ->groupBy('activities.id')
                ->orderBy('activities.schedule_from')
                ->get();

            $pipeline = $this->pipelineRepository->getDefaultPipeline();
            $stages = $pipeline ? $pipeline->stages()->get() : [];

            // Enrich appointments with calculated duration and doctor names if needed
            // For now, doctor_id is present. We can join doctors table if we want multiple doctor names per appointment,
            // but the current structure assumes one main doctor per doctor_activities entry or we group them.
            // Given the current query structure (leftJoin doctor_activities), if an activity has multiple doctors, it might duplicate rows or we need group_concat.
            // Let's assume for this view we want to show the doctor name.

            // Re-query to get better details including doctor name and lead status/pipeline stage if applicable.
            $appointments = DB::table('activities')
                ->leftJoin('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
                ->leftJoin('doctors', 'doctor_activities.doctor_id', '=', 'doctors.id')
                ->leftJoin('activity_participants', 'activities.id', '=', 'activity_participants.activity_id')
                ->leftJoin('persons as p', 'activity_participants.person_id', '=', 'p.id')
                ->leftJoin('lead_activities', 'activities.id', '=', 'lead_activities.activity_id')
                ->leftJoin('leads', 'lead_activities.lead_id', '=', 'leads.id')
                ->leftJoin('product_activities', 'activities.id', '=', 'product_activities.activity_id')
                ->leftJoin('products', 'product_activities.product_id', '=', 'products.id')
                ->select([
                    'activities.id',
                    'activities.title',
                    'activities.type',
                    'activities.comment',
                    'activities.is_done',
                    'activities.schedule_from as start',
                    'activities.schedule_to as end',
                    'doctor_activities.doctor_id',
                    'doctors.name as doctor_name',
                    'leads.id as lead_id',
                    'leads.lead_pipeline_stage_id',
                    'products.id as product_id',
                    'products.name as product_name',
                    DB::raw('COALESCE('.$prefix.'p.name, "") as person_name'),
                ])
                ->whereBetween('activities.schedule_from', [$startDate, $endDate])
                ->whereIn('activities.type', (function () {
                    $allowed = ['call', 'meeting', 'lunch', 'time_off'];
                    $types = request()->get('activity_types');
                    if (is_array($types)) {
                        $filtered = array_values(array_intersect($types, $allowed));

                        return count($filtered) ? $filtered : $allowed;
                    }

                    return $allowed;
                })())
                ->orderBy('activities.schedule_from')
                ->get();

            $availability = DB::table('doctor_shifts')
                ->select(['id', 'doctor_id', 'date', 'start_time', 'end_time'])
                ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                ->orderBy('doctor_id')
                ->orderBy('date')
                ->orderBy('start_time')
                ->get();

            return response()->json([
                'range'        => [
                    'start' => $start->format('Y-m-d'),
                    'end'   => $end->format('Y-m-d'),
                ],
                'days'         => $view === 'month'
                    ? collect(range(0, $start->daysInMonth - 1))->map(function ($i) use ($start) {
                        $d = $start->copy()->addDays($i);

                        return [
                            'date'  => $d->format('Y-m-d'),
                            'label' => $d->locale(app()->getLocale())->isoFormat('ddd D/MM'),
                        ];
                    })
                    : ($view === 'day'
                        ? collect([[
                            'date'  => $start->format('Y-m-d'),
                            'label' => $start->locale(app()->getLocale())->isoFormat('ddd D/MM'),
                        ]])
                        : collect(range(0, 6))->map(function ($i) use ($start) {
                            $d = $start->copy()->addDays($i);

                            return [
                                'date'  => $d->format('Y-m-d'),
                                'label' => $d->locale(app()->getLocale())->isoFormat('ddd D/MM'),
                            ];
                        })
                    ),
                'doctors'       => $doctors,
                'appointments'  => $appointments,
                'availability'  => $availability,
                'calendar_view' => $view,
                'stages'        => $stages,
            ]);
        }

        if (! request()->has('view_type')) {
            return datagrid(ActivityDataGrid::class)->process();
        }

        $startDate = request()->get('startDate')
            ? Carbon::createFromTimeString(request()->get('startDate').' 00:00:01')
            : Carbon::now()->startOfWeek()->format('Y-m-d H:i:s');

        $endDate = request()->get('endDate')
            ? Carbon::createFromTimeString(request()->get('endDate').' 23:59:59')
            : Carbon::now()->endOfWeek()->format('Y-m-d H:i:s');

        $activities = $this->activityRepository->getActivities([$startDate, $endDate])->toArray();

        return response()->json([
            'activities' => $activities,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse|JsonResponse
    {
        $this->validate(request(), [
            'type'          => 'required',
            'comment'       => 'required_if:type,note',
            'schedule_from' => 'required_unless:type,note,file',
            'schedule_to'   => 'required_unless:type,note,file',
            'file'          => 'required_if:type,file',
        ]);

        // --- UNIFICACIÓN: Si es una consulta (meeting), usamos la lógica robusta ---
        if (request('type') === 'meeting') {
            $participants = request()->input('participants');
            $doctorId = ($participants['doctors'] ?? [])[0] ?? null;
            $personId = ($participants['persons'] ?? [])[0] ?? null;

            if (! $doctorId || ! $personId) {
                $msg = 'Debes seleccionar al menos un Doctor y un Paciente para agendar una consulta.';
                if (request()->ajax()) {
                    return response()->json(['message' => $msg], 422);
                }
                session()->flash('error', $msg);

                return redirect()->back();
            }

            // Adaptamos los datos para el procesador unificado
            $appointmentData = [
                'person'        => ['id' => $personId],
                'doctor_id'     => $doctorId,
                'product_id'    => request('product_id'),
                'title'         => request('title'),
                'reason'        => request('comment'),
                'schedule_from' => request('schedule_from'),
                'schedule_to'   => request('schedule_to'),
            ];

            return $this->processMedicalAppointment($appointmentData);
        }

        Event::dispatch('activity.create.before');

        $activity = $this->activityRepository->create(array_merge(request()->all(), [
            'is_done' => request('type') == 'note' ? 1 : 0,
            'user_id' => auth()->guard('user')->user()->id,
        ]));

        Event::dispatch('activity.create.after', $activity);

        if (request()->ajax()) {
            return response()->json([
                'data'    => new ActivityResource($activity),
                'message' => trans('admin::app.activities.create-success'),
            ]);
        }

        session()->flash('success', trans('admin::app.activities.create-success'));

        return redirect()->back();
    }

    public function storeAppointment(): JsonResponse
    {
        $this->validate(request(), [
            'person'                 => 'required|array',
            'person.id'              => 'nullable|exists:persons,id',
            'person.name'            => 'required|string|max:255',
            'person.phone'           => 'required_without:person.id|nullable|string|min:7|max:20',
            'doctor_id'              => 'required|exists:doctors,id',
            'product_id'             => 'required|exists:products,id',
            'date'                   => 'required|date_format:Y-m-d',
            'start_time'             => 'required|date_format:H:i',
            'end_time'               => 'nullable|date_format:H:i',
            'duration_minutes'       => 'nullable|integer|min:5|max:480',
            'reason'                 => 'nullable|string',
        ]);

        $date = request('date');
        $startTime = request('start_time');
        $scheduleFrom = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$startTime}");

        if (! empty(request('duration_minutes'))) {
            $scheduleTo = $scheduleFrom->copy()->addMinutes((int) request('duration_minutes'));
        } else {
            $scheduleTo = Carbon::createFromFormat('Y-m-d H:i', "{$date} ".request('end_time'));
        }

        $appointmentData = [
            'person'        => request('person'),
            'doctor_id'     => request('doctor_id'),
            'product_id'    => request('product_id'),
            'reason'        => request('reason'),
            'schedule_from' => $scheduleFrom->format('Y-m-d H:i:s'),
            'schedule_to'   => $scheduleTo->format('Y-m-d H:i:s'),
        ];

        return $this->processMedicalAppointment($appointmentData);
    }

    /**
     * Procesador Unificado de Consultas Médicas (Cerebro Compartido)
     */
    private function processMedicalAppointment(array $data): JsonResponse|RedirectResponse
    {
        $scheduleFrom = Carbon::parse($data['schedule_from']);
        $scheduleTo = Carbon::parse($data['schedule_to']);
        $doctorId = $data['doctor_id'];
        $personData = $data['person'];
        $productId = $data['product_id'];

        $doctor = DB::table('doctors')->where('id', $doctorId)->first();
        $doctorExternalId = $doctor?->unique_id;
        $doctorEmail = $doctor?->email;

        // 1. Validar Conflictos Locales (Actividades existentes)
        $localConflict = DB::table('activities')
            ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
            ->where('doctor_activities.doctor_id', $doctorId)
            ->where(function ($query) use ($scheduleFrom, $scheduleTo) {
                $query->where(function ($q) use ($scheduleFrom, $scheduleTo) {
                    $q->where('schedule_from', '>=', $scheduleFrom)
                        ->where('schedule_from', '<', $scheduleTo);
                })->orWhere(function ($q) use ($scheduleFrom, $scheduleTo) {
                    $q->where('schedule_to', '>', $scheduleFrom)
                        ->where('schedule_to', '<=', $scheduleTo);
                })->orWhere(function ($q) use ($scheduleFrom, $scheduleTo) {
                    $q->where('schedule_from', '<=', $scheduleFrom)
                        ->where('schedule_to', '>=', $scheduleTo);
                });
            })
            ->exists();

        if ($localConflict) {
            $msg = 'El doctor ya tiene una cita programada en este horario en el sistema local.';
            if (request()->ajax()) {
                return response()->json(['message' => $msg], 422);
            }
            session()->flash('error', $msg);

            return redirect()->back();
        }

        // 2. Validar Jornada Laboral (Turnos/Shifts)
        $hasValidShift = DB::table('doctor_shifts')
            ->where('doctor_id', $doctorId)
            ->where('date', $scheduleFrom->toDateString())
            ->where('start_time', '<=', $scheduleFrom->format('H:i'))
            ->where('end_time', '>=', $scheduleTo->format('H:i'))
            ->exists();

        if (! $hasValidShift) {
            $msg = 'El horario seleccionado está fuera de la jornada laboral del doctor para este día. Por favor, revisa los turnos disponibles.';
            if (request()->ajax()) {
                return response()->json(['message' => $msg], 422);
            }
            session()->flash('error', $msg);

            return redirect()->back();
        }

        // 3. Descubrimiento Automático de Doctor en SMD (Si falta ID o Email)
        if (empty($doctorExternalId) || empty($doctorEmail)) {
            $doctorRepo = app(\Webkul\Doctor\Repositories\DoctorRepository::class);
            $doctorModel = $doctorRepo->with('specialties')->find($doctorId);
            $discoverySpecialties = $doctorModel->specialties->pluck('name')->toArray();

            // Si el doctor no tiene especialidades en el CRM, intentamos con todas las del sistema
            if (empty($discoverySpecialties)) {
                $discoverySpecialties = app(\Webkul\Doctor\Repositories\SpecialtyRepository::class)->pluck('name')->toArray();
            }

            if (empty($discoverySpecialties)) {
                $discoverySpecialties = ['General'];
            }

            $found = false;
            foreach ($discoverySpecialties as $spec) {
                $this->shareMeDataService->checkAvailability(null, $spec, 'Santa Cruz', $scheduleFrom->format('Y-m-d H:i:s'), $scheduleTo->format('Y-m-d H:i:s'));
                $raw = $this->shareMeDataService->getLastResponse();
                if ($raw && isset($raw['body']) && is_array($raw['body'])) {
                    foreach ($raw['body'] as $item) {
                        $smdName = trim(strtolower(($item['physician']['name'] ?? '').' '.($item['physician']['lastName'] ?? '')));
                        if ($smdName === trim(strtolower($doctor->name))) {
                            $doctorExternalId = $item['physician']['_id'] ?? null;
                            $doctorEmail = $item['physician']['email'] ?? null;
                            if ($doctorExternalId) {
                                $doctorRepo->update(['unique_id' => $doctorExternalId, 'email' => $doctorEmail], $doctor->id);
                                $found = true;
                                break 2;
                            }
                        }
                    }
                }
            }

            if (! $found) {
                $msg = "No se pudo vincular al doctor {$doctor->name} con ShareMeData. Verifica que el nombre coincida exactamente.";
                if (request()->ajax()) {
                    return response()->json(['message' => $msg], 422);
                }
                session()->flash('error', $msg);

                return redirect()->back();
            }
        }

        // 4. Validar Disponibilidad en SMD (Búsqueda Inteligente por Nombre/Especialidad)
        $doctorRepo = app(\Webkul\Doctor\Repositories\DoctorRepository::class);
        $doctorModel = $doctorRepo->with('specialties')->find($doctorId);
        $doctorSpecialties = $doctorModel->specialties->pluck('name')->toArray();

        // Si el doctor no tiene especialidades en el CRM, intentamos con todas las disponibles en el sistema
        if (empty($doctorSpecialties)) {
            $doctorSpecialties = app(\Webkul\Doctor\Repositories\SpecialtyRepository::class)->pluck('name')->toArray();
        }

        // Si aún así no hay nada, usamos 'General' como último recurso
        if (empty($doctorSpecialties)) {
            $doctorSpecialties = ['General'];
        }

        $isAvailableExternally = false;
        $smdErrors = [];
        $smdLastResponses = [];

        foreach ($doctorSpecialties as $specialty) {
            $slots = $this->shareMeDataService->checkAvailability($doctorExternalId, $specialty, 'Santa Cruz', $scheduleFrom->format('Y-m-d H:i:s'), $scheduleTo->format('Y-m-d H:i:s'));
            $lastResponse = $this->shareMeDataService->getLastResponse();
            $smdLastResponses[$specialty] = $lastResponse;

            if (! empty($slots)) {
                $requiredIntervals = [];
                $current = $scheduleFrom->copy();
                while ($current->lessThan($scheduleTo)) {
                    $requiredIntervals[] = ['start' => $current->timestamp, 'end' => $current->addMinutes(15)->timestamp];
                }

                $foundCount = 0;
                foreach ($requiredIntervals as $required) {
                    foreach ($slots as $daySlots) {
                        foreach ($daySlots as $date => $intervals) {
                            foreach ($intervals as $interval) {
                                if (Carbon::parse($interval['start'])->timestamp === $required['start'] && Carbon::parse($interval['end'])->timestamp === $required['end']) {
                                    $foundCount++;

                                    continue 3;
                                }
                            }
                        }
                    }
                }

                if ($foundCount === count($requiredIntervals)) {
                    $isAvailableExternally = true;
                    break;
                } else {
                    $smdErrors[$specialty] = "Solo se encontraron $foundCount de ".count($requiredIntervals).' bloques de 15m libres.';
                }
            } else {
                $smdErrors[$specialty] = $lastResponse['body']['message'] ?? 'Sin disponibilidad devuelta por SMD';
            }
        }

        if (! $isAvailableExternally) {
            $msg = 'El doctor no tiene disponibilidad en SHAREMEDATA para el horario solicitado.';
            if (request()->ajax()) {
                return response()->json([
                    'message'        => $msg,
                    'smd_errors'     => $smdErrors,
                    'smd_responses'  => $smdLastResponses,
                    'doctor_id_used' => $doctorExternalId,
                ], 422);
            }
            session()->flash('error', $msg.' Detalle: '.json_encode($smdErrors));

            return redirect()->back();
        }

        $product = DB::table('products')->where('id', $productId)->first();

        // 5. Creación Local y Sincronización SMD
        try {
            return DB::transaction(function () use ($data, $scheduleFrom, $scheduleTo, $doctor, $doctorExternalId, $doctorEmail, $personData, $productId, $product) {
                // Gestión de Prospecto
                $personId = $personData['id'] ?? null;
                $person = $this->personRepository->find($personId);

                // Obtener teléfono real del paciente (Validación de Kommo Email)
                $personPhone = '77788990';
                if ($person && ! empty($person->contact_numbers)) {
                    $contactNumbers = is_array($person->contact_numbers) ? $person->contact_numbers : json_decode($person->contact_numbers, true);
                    if (is_array($contactNumbers) && count($contactNumbers) > 0 && isset($contactNumbers[0]['value'])) {
                        $personPhone = (string) $contactNumbers[0]['value'];
                    }
                }

                // Creación de Lead
                $leadTitle = ($person->name ?? 'Paciente').' - '.($product->name ?? 'Consulta');
                $leadData = [
                    'title'       => $leadTitle,
                    'description' => $data['reason'] ?? '',
                    'entity_type' => 'leads',
                    'person'      => ['id' => $person->id],
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('leads', 'doctor_id')) {
                    $leadData['doctor_id'] = $doctor->id;
                }
                $lead = $this->leadRepository->create($leadData);

                // Creación de Actividad
                $activity = $this->activityRepository->create([
                    'type'          => 'meeting',
                    'title'         => $leadTitle,
                    'comment'       => $data['reason'] ?? '',
                    'schedule_from' => $scheduleFrom->format('Y-m-d H:i:s'),
                    'schedule_to'   => $scheduleTo->format('Y-m-d H:i:s'),
                    'user_id'       => auth()->guard('user')->user()->id ?? 1,
                    'participants'  => ['doctors' => [$doctor->id], 'persons' => [$person->id]],
                ]);
                $activity->leads()->sync([$lead->id]);
                if ($productId) {
                    $activity->products()->sync([$productId]);
                }

                // Sincronización SMD Outbound
                $nameParts = explode(' ', trim($person->name ?? 'Paciente'));
                $firstName = $nameParts[0] ?: 'Paciente';
                $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'Externo';

                $shareMeData = [
                    'summary'   => 'CONSULTA: '.$leadTitle,
                    'physician' => ['_id' => $doctorExternalId, 'email' => $doctorEmail ?: ''],
                    'patient'   => ['name' => (string) $firstName, 'lastName' => (string) $lastName, 'phone' => (string) $personPhone, 'personID' => '', 'birthday' => ''],
                    'slot'      => ['start' => $scheduleFrom->format('Y-m-d\TH:i:s-04:00'), 'end' => $scheduleTo->format('Y-m-d\TH:i:s-04:00')],
                ];
                $this->shareMeDataService->createEvent($shareMeData);

                if (request()->ajax()) {
                    return response()->json(['lead_id' => $lead->id, 'activity_id' => $activity->id, 'message' => 'Cita creada y sincronizada correctamente.']);
                }
                session()->flash('success', 'Cita creada y sincronizada correctamente.');

                return redirect()->back();
            });
        } catch (\Exception $e) {
            Log::error('SMD Logic Error: '.$e->getMessage());
            $msg = 'Error interno al crear la cita.';
            if (request()->ajax()) {
                return response()->json(['message' => $msg], 500);
            }
            session()->flash('error', $msg);

            return redirect()->back();
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id): View
    {
        $activity = $this->activityRepository->with(['participants', 'doctors'])->findOrFail($id);

        $leadId = old('lead_id') ?? optional($activity->leads()->first())->id;

        $lookUpEntityData = $this->attributeRepository->getLookUpEntity('leads', $leadId);

        return view('admin::activities.edit', compact('activity', 'lookUpEntityData'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update($id): RedirectResponse|JsonResponse
    {
        Event::dispatch('activity.update.before', $id);

        $data = request()->all();

        $activity = $this->activityRepository->update($data, $id);

        /**
         * We will not use `empty` directly here because `lead_id` can be a blank string
         * from the activity form. However, on the activity view page, we are only updating the
         * `is_done` field, so `lead_id` will not be present in that case.
         */
        if (isset($data['lead_id'])) {
            $lead = $this->leadRepository->find($data['lead_id']);

            if ($lead) {
                $activity->leads()->sync([$data['lead_id']]);

                if (isset($data['lead_pipeline_stage_id'])) {
                    $lead->update(['lead_pipeline_stage_id' => $data['lead_pipeline_stage_id']]);
                }
            } else {
                $activity->leads()->sync([]);
            }
        }

        if (isset($data['product_id'])) {
            $activity->products()->sync(
                ! empty($data['product_id'])
                    ? [$data['product_id']]
                    : []
            );
        }

        if (isset($data['doctor_id'])) {
            $activity->doctors()->sync(
                ! empty($data['doctor_id'])
                    ? [$data['doctor_id']]
                    : []
            );
        }

        Event::dispatch('activity.update.after', $activity);

        if (request()->ajax()) {
            return response()->json([
                'data'    => new ActivityResource($activity),
                'message' => trans('admin::app.activities.update-success'),
            ]);
        }

        session()->flash('success', trans('admin::app.activities.update-success'));

        return redirect()->route('admin.activities.index');
    }

    /**
     * Mass Update the specified resources.
     */
    public function massUpdate(MassUpdateRequest $massUpdateRequest): JsonResponse
    {
        $activities = $this->activityRepository->findWhereIn('id', $massUpdateRequest->input('indices'));

        foreach ($activities as $activity) {
            Event::dispatch('activity.update.before', $activity->id);

            $activity = $this->activityRepository->update([
                'is_done' => $massUpdateRequest->input('value'),
            ], $activity->id);

            Event::dispatch('activity.update.after', $activity);
        }

        return response()->json([
            'message' => trans('admin::app.activities.mass-update-success'),
        ]);
    }

    /**
     * Download file from storage.
     */
    public function download(int $id): StreamedResponse
    {
        try {
            $file = $this->fileRepository->findOrFail($id);

            return Storage::download($file->path);
        } catch (\Exception $exception) {
            abort(404);
        }
    }

    /*
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $activity = $this->activityRepository->findOrFail($id);

        try {
            Event::dispatch('activity.delete.before', $id);

            $activity?->delete($id);

            Event::dispatch('activity.delete.after', $id);

            return response()->json([
                'message' => trans('admin::app.activities.destroy-success'),
            ], 200);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.activities.destroy-failed'),
            ], 400);
        }
    }

    /**
     * Mass Delete the specified resources.
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $activities = $this->activityRepository->findWhereIn('id', $massDestroyRequest->input('indices'));

        try {
            foreach ($activities as $activity) {
                Event::dispatch('activity.delete.before', $activity->id);

                $this->activityRepository->delete($activity->id);

                Event::dispatch('activity.delete.after', $activity->id);
            }

            return response()->json([
                'message' => trans('admin::app.activities.mass-destroy-success'),
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => trans('admin::app.activities.mass-delete-failed'),
            ], 400);
        }
    }
}
