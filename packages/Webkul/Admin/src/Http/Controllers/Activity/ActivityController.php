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
use Webkul\Admin\Exceptions\AppointmentException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Admin\Http\Resources\ActivityResource;
use Webkul\Admin\Services\AppointmentService;
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
        protected PersonRepository $personRepository,
        protected AppointmentService $appointmentService,
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
            $view = request()->get('calendar_view', 'week'); // 'week' | 'month' | 'day' | 'list'
            if ($view === 'list') {
                // Vista lista por rango arbitrario (filtro Desde/Hasta).
                $start = request()->get('range_start')
                    ? Carbon::parse(request()->get('range_start'))->startOfDay()
                    : Carbon::now()->startOfDay();
                $end = request()->get('range_end')
                    ? Carbon::parse(request()->get('range_end'))->endOfDay()
                    : Carbon::now()->copy()->addDays(30)->endOfDay();

                // Si el usuario invierte las fechas, las normalizamos.
                if ($end->lt($start)) {
                    [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
                }
            } elseif ($view === 'month') {
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

            // Solo activos: los recursos de SMD (quirófano, recepcionista) están en
            // `doctors` pero desactivados y no deben ofrecerse para agendar.
            $doctors = DB::table('doctors')
                ->select(['id', 'name'])
                ->where('is_active', 1)
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
                    'leads.status as lead_status',
                    'leads.lead_pipeline_stage_id',
                    'products.id as product_id',
                    'products.name as product_name',
                    DB::raw('MAX('.$prefix.'p.id) as person_id'),
                    DB::raw('MAX('.$prefix.'p.name) as person_name'),
                    // Se extraen con JSON_EXTRACT porque MAX() no acepta columnas JSON.
                    // Alimentan person_whatsapp (ver resolveWhatsappNumber).
                    DB::raw('MAX(JSON_UNQUOTE(JSON_EXTRACT('.$prefix.'p.emails, \'$[0].value\'))) as person_email'),
                    DB::raw('MAX(JSON_UNQUOTE(JSON_EXTRACT('.$prefix.'p.contact_numbers, \'$[0].value\'))) as person_contact_number'),
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
                ->groupBy([
                    'activities.id',
                    'activities.title',
                    'activities.type',
                    'activities.comment',
                    'activities.is_done',
                    'activities.schedule_from',
                    'activities.schedule_to',
                    'doctor_activities.doctor_id',
                    'doctors.name',
                    'leads.id',
                    'leads.status',
                    'leads.lead_pipeline_stage_id',
                    'products.id',
                    'products.name',
                ])
                ->orderBy('activities.schedule_from')
                ->get();

            // Misma regla de "cancelado" que usa AppointmentService::process() para
            // liberar el slot: status=0 o stage en smd.stage_map.cancelled/no_show.
            // Se calcula aqui (no en el query) porque solo aplica a la visualizacion.
            $cancelledStageIds = array_filter([
                (int) config('smd.stage_map.cancelled'),
                (int) config('smd.stage_map.no_show'),
            ]);
            $completedStageIds = array_filter([
                (int) config('smd.stage_map.completed'),
            ]);

            $appointments = $appointments->map(function ($appointment) use ($cancelledStageIds, $completedStageIds) {
                // La cancelación se determina SOLO por la etapa "Cancelado" del lead
                // (es lo que setean la sync de ShareMeData y el botón Cancelar).
                // NO por lead_status: en odontoking status=0 aparece también en leads
                // en "Cita confirmadas", así que usarlo marcaba citas confirmadas como
                // canceladas (falso positivo).
                $stageIsCancelled = $appointment->lead_pipeline_stage_id !== null
                    && in_array((int) $appointment->lead_pipeline_stage_id, $cancelledStageIds, true);
                $stageIsCompleted = $appointment->lead_pipeline_stage_id !== null
                    && in_array((int) $appointment->lead_pipeline_stage_id, $completedStageIds, true);

                $appointment->is_cancelled = (bool) $appointment->lead_id && $stageIsCancelled;
                // Cancelado tiene prioridad visual sobre completado si por algún motivo
                // coincidieran (no deberían, son stages distintos en stage_map).
                $appointment->is_completed = (bool) $appointment->lead_id && $stageIsCompleted && ! $appointment->is_cancelled;

                $appointment->person_whatsapp = $this->resolveWhatsappNumber(
                    $appointment->person_email,
                    $appointment->person_contact_number
                );

                unset($appointment->person_email, $appointment->person_contact_number);

                return $appointment;
            });

            $availability = DB::table('doctor_shifts')
                ->select(['id', 'doctor_id', 'date', 'start_time', 'end_time'])
                ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                ->orderBy('doctor_id')
                ->orderBy('date')
                ->orderBy('start_time')
                ->get();

            // Transformar bloques de disponibilidad en slots de 60 minutos
            $availability = $this->generateSlotsFromAvailability($availability);

            return response()->json([
                'range'        => [
                    'start' => $start->format('Y-m-d'),
                    'end'   => $end->format('Y-m-d'),
                ],
                'days'         => $view === 'list'
                    ? collect([])
                    : ($view === 'month'
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
                    )),
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
                'lead_id'       => request('lead_id') ?: null,
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
            'product_id'             => 'nullable|exists:products,id',
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
            'lead_id'       => request('lead_id') ?: null,
            'reason'        => request('reason'),
            'schedule_from' => $scheduleFrom->format('Y-m-d H:i:s'),
            'schedule_to'   => $scheduleTo->format('Y-m-d H:i:s'),
        ];

        return $this->processMedicalAppointment($appointmentData);
    }

    /**
     * Procesador unificado de consultas médicas. Delega en AppointmentService.
     */
    private function processMedicalAppointment(array $data): JsonResponse|RedirectResponse
    {
        try {
            $result = $this->appointmentService->process($data);

            if (request()->ajax()) {
                // Devolver ActivityResource para que el evento on-activity-added
                // reciba el formato correcto y pueda renderizar en el panel de actividades.
                $activity = $this->activityRepository
                    ->with(['participants', 'doctors', 'leads'])
                    ->find($result['activity_id']);

                return response()->json([
                    'data'    => new ActivityResource($activity),
                    'message' => $result['message'],
                ]);
            }

            session()->flash('success', $result['message']);

            return redirect()->back();
        } catch (AppointmentException $e) {
            if (request()->ajax()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'details' => $e->getDetails(),
                ], 422);
            }

            session()->flash('error', $e->getMessage());

            return redirect()->back();
        } catch (\Exception $e) {
            Log::error('AppointmentService error: '.$e->getMessage());

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

        // Capturar valores originales antes de la actualización para el historial de cambios.
        $existing = $this->activityRepository->findOrFail($id);
        $original = $existing->only([
            'title', 'comment', 'schedule_from', 'schedule_to', 'is_done', 'location',
        ]);

        // ── Validación de horarios al editar una consulta (meeting) ──────────
        if (
            ($data['type'] ?? $existing->type) === 'meeting'
            && (isset($data['schedule_from']) || isset($data['schedule_to']))
        ) {
            $scheduleFrom = Carbon::parse($data['schedule_from'] ?? $existing->schedule_from);
            $scheduleTo = Carbon::parse($data['schedule_to'] ?? $existing->schedule_to);

            // Obtener doctor_id actual o del request (ignorar string vacío)
            $doctorId = (! empty($data['doctor_id']) ? $data['doctor_id'] : null)
                ?? $existing->doctors()->value('doctors.id');

            if ($doctorId) {
                // 1. Validar que el nuevo horario esté dentro de un turno del doctor
                $hasValidShift = DB::table('doctor_shifts')
                    ->where('doctor_id', $doctorId)
                    ->where('date', $scheduleFrom->toDateString())
                    ->where('start_time', '<=', $scheduleFrom->format('H:i'))
                    ->where('end_time', '>=', $scheduleTo->format('H:i'))
                    ->exists();

                if (! $hasValidShift) {
                    $msg = 'El horario seleccionado está fuera de la jornada laboral del doctor para este día.';
                    if (request()->ajax()) {
                        return response()->json(['message' => $msg], 422);
                    }
                    session()->flash('error', $msg);

                    return redirect()->back();
                }

                // 2. Validar que no haya conflicto con otras citas del doctor
                // (excluyendo la propia actividad que se está editando)
                $localConflict = DB::table('activities')
                    ->join('doctor_activities', 'activities.id', '=', 'doctor_activities.activity_id')
                    ->where('doctor_activities.doctor_id', $doctorId)
                    ->where('activities.id', '!=', $id)
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
                    $msg = 'El doctor ya tiene una cita programada en este horario.';
                    if (request()->ajax()) {
                        return response()->json(['message' => $msg], 422);
                    }
                    session()->flash('error', $msg);

                    return redirect()->back();
                }
            }
        }

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

        // ── Registrar cambios en el historial ────────────────────────────────
        $this->logActivityChanges($activity, $original, $data);

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
     * Registra en el historial (activities tipo "system") los campos que cambiaron
     * al editar una Activity (consulta). Las entradas se adjuntan al Lead y/o Person
     * asociados para que aparezcan en sus respectivos historiales.
     */
    private function logActivityChanges($activity, array $original, array $newData): void
    {
        $trackFields = [
            'title'         => 'Título',
            'comment'       => 'Comentario',
            'schedule_from' => 'Inicio',
            'schedule_to'   => 'Fin',
            'is_done'       => 'Completada',
            'location'      => 'Ubicación',
        ];

        $changed = [];

        foreach ($trackFields as $field => $label) {
            if (! array_key_exists($field, $newData)) {
                continue;
            }

            $oldRaw = $original[$field] ?? null;
            $newRaw = $newData[$field] ?? null;

            // Normalizar valores de fecha para comparación sin segundos y timezone
            if (in_array($field, ['schedule_from', 'schedule_to'])) {
                $oldNorm = $oldRaw ? Carbon::parse($oldRaw)->format('Y-m-d H:i') : null;
                $newNorm = $newRaw ? Carbon::parse($newRaw)->format('Y-m-d H:i') : null;
            } else {
                $oldNorm = (string) ($oldRaw ?? '');
                $newNorm = (string) ($newRaw ?? '');
            }

            if ($oldNorm !== $newNorm) {
                $changed[$field] = ['label' => $label, 'old' => $oldRaw, 'new' => $newRaw];
            }
        }

        if (empty($changed)) {
            return;
        }

        // Obtener leads y persons asociados para adjuntar el log
        $leads = $activity->leads()->pluck('leads.id')->toArray();
        $persons = $activity->persons()->pluck('persons.id')->toArray();

        foreach ($changed as $field => $info) {
            try {
                $systemActivity = $this->activityRepository->create([
                    'type'       => 'system',
                    'title'      => trans('admin::app.activities.updated', ['attribute' => $info['label']]),
                    'is_done'    => 1,
                    'additional' => json_encode([
                        'attribute' => $info['label'],
                        'new'       => ['value' => $info['new'], 'label' => $info['new']],
                        'old'       => ['value' => $info['old'], 'label' => $info['old']],
                    ]),
                    'user_id'    => auth()->guard('user')->id() ?? auth()->id(),
                ]);

                foreach ($leads as $leadId) {
                    DB::table('lead_activities')->insertOrIgnore([
                        'lead_id'     => $leadId,
                        'activity_id' => $systemActivity->id,
                    ]);
                }

                foreach ($persons as $personId) {
                    DB::table('person_activities')->insertOrIgnore([
                        'person_id'   => $personId,
                        'activity_id' => $systemActivity->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Error registrando historial de cambio en Activity: '.$e->getMessage(), [
                    'activity_id' => $activity->id,
                    'field'       => $field,
                ]);
            }
        }
    }

    /**
     * Confirm / cancel / complete an appointment from the calendar. Moves the
     * appointment's lead to the mapped pipeline stage (smd.stage_map), which is
     * what drives is_cancelled / is_completed in the calendar events.
     *
     * Used after a patient replies SÍ/NO to a WhatsApp reminder.
     */
    public function updateAppointmentStatus(int $id): JsonResponse
    {
        $data = request()->validate([
            'status' => 'required|in:confirmed,cancelled,completed',
        ]);

        $activity = $this->activityRepository->findOrFail($id);

        $leadIds = $activity->leads()->pluck('leads.id');

        if ($leadIds->isEmpty()) {
            return response()->json([
                'message' => 'La cita no tiene un lead asociado; no se puede cambiar el estado.',
            ], 422);
        }

        $stageId = (int) config('smd.stage_map.'.$data['status']);

        DB::table('leads')
            ->whereIn('id', $leadIds)
            ->update(['lead_pipeline_stage_id' => $stageId, 'updated_at' => now()]);

        $labels = ['confirmed' => 'confirmada', 'cancelled' => 'cancelada', 'completed' => 'atendida'];

        return response()->json([
            'message' => 'Cita marcada como '.$labels[$data['status']].'.',
            'status'  => $data['status'],
        ]);
    }

    /**
     * A patient's appointment history (meeting activities), newest first, with
     * doctor and derived state. Shown in the appointment detail modal.
     */
    public function personAppointments(int $personId): JsonResponse
    {
        $cancelledStageIds = array_filter([(int) config('smd.stage_map.cancelled'), (int) config('smd.stage_map.no_show')]);
        $completedStageIds = array_filter([(int) config('smd.stage_map.completed')]);

        $rows = DB::table('activities')
            ->join('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
            ->leftJoin('doctor_activities', 'doctor_activities.activity_id', '=', 'activities.id')
            ->leftJoin('doctors', 'doctors.id', '=', 'doctor_activities.doctor_id')
            ->leftJoin('lead_activities', 'lead_activities.activity_id', '=', 'activities.id')
            ->leftJoin('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->where('activity_participants.person_id', $personId)
            ->where('activities.type', 'meeting')
            ->groupBy('activities.id')
            ->orderByDesc('activities.schedule_from')
            ->limit(50)
            ->get([
                'activities.id',
                'activities.schedule_from as start',
                DB::raw('MAX('.DB::getTablePrefix().'doctors.name) as doctor_name'),
                DB::raw('MAX('.DB::getTablePrefix().'leads.lead_pipeline_stage_id) as stage_id'),
            ]);

        $appointments = $rows->map(function ($r) use ($cancelledStageIds, $completedStageIds) {
            $state = in_array((int) $r->stage_id, $cancelledStageIds, true) ? 'cancelled'
                : (in_array((int) $r->stage_id, $completedStageIds, true) ? 'completed' : 'confirmed');

            return [
                'id'          => $r->id,
                'date'        => $r->start ? Carbon::parse($r->start)->format('d/m/Y') : null,
                'time'        => $r->start ? Carbon::parse($r->start)->format('H:i') : null,
                'doctor_name' => $r->doctor_name,
                'state'       => $state,
            ];
        });

        return response()->json([
            'appointments' => $appointments->values(),
            'total'        => $appointments->count(),
        ]);
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

    /**
     * Devuelve el número del paciente en el formato que exige wa.me: solo
     * dígitos y con código de país.
     *
     * Misma prioridad que PersonController::extractWaId(): primero el email
     * `<wa_id>@whatsapp.*`, que IncomingAppointmentService ya guarda
     * normalizado, y como respaldo el teléfono de contacto, que puede venir
     * capturado a mano con espacios, guiones o sin prefijo.
     */
    private function resolveWhatsappNumber(?string $email, ?string $contactNumber): ?string
    {
        if ($email && str_contains($email, '@whatsapp.')) {
            $waId = $this->normalizePhone(explode('@', $email)[0]);

            if ($waId !== null) {
                return $waId;
            }
        }

        return $this->normalizePhone($contactNumber ?? '');
    }

    /**
     * Un número local boliviano tiene 8 dígitos; con el prefijo 591 llega a 11.
     * Por eso solo se antepone el código de país cuando el número no lo trae ya.
     */
    private function normalizePhone(string $value): ?string
    {
        $digits = ltrim(preg_replace('/[^0-9]/', '', $value), '0');

        if ($digits === '') {
            return null;
        }

        $countryCode = (string) config('smd.whatsapp_country_code', '591');

        if ($countryCode !== '' && ! str_starts_with($digits, $countryCode)) {
            $digits = $countryCode.$digits;
        }

        return $digits;
    }

    /**
     * Transforma bloques continuos de disponibilidad en slots de tiempo fijos.
     *
     * @param  \Illuminate\Support\Collection  $availability
     * @param  int  $durationMinutes
     * @return \Illuminate\Support\Collection
     */
    private function generateSlotsFromAvailability($availability, $durationMinutes = 60)
    {
        $slots = collect();

        foreach ($availability as $block) {
            $current = Carbon::parse($block->date.' '.$block->start_time);
            $end = Carbon::parse($block->date.' '.$block->end_time);

            while ($current->copy()->addMinutes($durationMinutes)->lte($end)) {
                $slots->push([
                    'id'         => $block->id, // Mantenemos el ID del bloque original
                    'doctor_id'  => $block->doctor_id,
                    'date'       => $block->date,
                    'start_time' => $current->format('H:i:s'),
                    'end_time'   => $current->copy()->addMinutes($durationMinutes)->format('H:i:s'),
                ]);

                $current->addMinutes($durationMinutes);
            }
        }

        return $slots;
    }
}
