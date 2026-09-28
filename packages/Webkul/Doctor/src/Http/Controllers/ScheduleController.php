<?php

namespace Webkul\Doctor\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Repositories\ShiftRepository;

class ScheduleController extends Controller
{
    public function __construct(
        protected DoctorRepository $doctorRepository,
        protected ShiftRepository $shiftRepository
    ) {}

    public function index(): View
    {
        return view('doctor::schedules.index');
    }

    public function week(Request $request): JsonResponse
    {
        $start = Carbon::parse($request->query('start', Carbon::now()->startOfWeek()));
        $end = (clone $start)->endOfWeek();

        $doctors = $this->doctorRepository->all();

        $shifts = $this->shiftRepository->findWhere([
            ['date', '>=', $start->toDateString()],
            ['date', '<=', $end->toDateString()],
        ]);

        $days = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $days[] = [
                'date'  => $cursor->toDateString(),
                'label' => $cursor->isoFormat('ddd, D MMM'),
            ];
            $cursor->addDay();
        }

        $data = [
            'range' => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
            'days'    => $days,
            'doctors' => $doctors->map(fn ($d) => [
                'id'   => $d->id,
                'name' => $d->name,
            ])->values(),
            'shifts' => $shifts->map(fn ($s) => [
                'id'         => $s->id,
                'doctor_id'  => $s->doctor_id,
                'date'       => $s->date->toDateString(),
                'start_time' => substr($s->start_time, 0, 5),
                'end_time'   => substr($s->end_time, 0, 5),
                'notes'      => $s->notes,
            ])->values(),
        ];

        return new JsonResponse($data);
    }

    public function create(): View
    {
        $doctors = $this->doctorRepository->all();

        return view('doctor::schedules.form', compact('doctors'));
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->has('recurrence')) {
            if ($request->has('shifts')) {
                $request->validate([
                    'shifts'              => ['required', 'array'],
                    'shifts.*.doctor_id'  => ['required', 'integer', 'exists:doctors,id'],
                    'shifts.*.start_date' => ['required', 'date'],
                    'shifts.*.end_date'   => ['required', 'date', 'after_or_equal:shifts.*.start_date'],
                    'shifts.*.days'       => ['required', 'array'],
                    'shifts.*.start_time' => ['required', 'date_format:H:i'],
                    'shifts.*.end_time'   => ['required', 'date_format:H:i', 'after:shifts.*.start_time'],
                ]);

                $createdCount = 0;
                foreach ($request->input('shifts') as $shiftData) {
                    $startDate = Carbon::parse($shiftData['start_date']);
                    $endDate = Carbon::parse($shiftData['end_date']);
                    $days = $shiftData['days'];

                    $cursor = $startDate->copy();
                    while ($cursor->lte($endDate)) {
                        if (in_array($cursor->dayOfWeek, $days)) {
                            $dateStr = $cursor->toDateString();

                            $conflict = DB::table('doctor_shifts')
                                ->where('doctor_id', $shiftData['doctor_id'])
                                ->where('date', $dateStr)
                                ->where('start_time', '<', $shiftData['end_time'])
                                ->where('end_time', '>', $shiftData['start_time'])
                                ->exists();

                            if (! $conflict) {
                                $this->shiftRepository->create([
                                    'doctor_id'  => $shiftData['doctor_id'],
                                    'date'       => $dateStr,
                                    'start_time' => $shiftData['start_time'],
                                    'end_time'   => $shiftData['end_time'],
                                    'notes'      => $shiftData['notes'] ?? null,
                                ]);
                                $createdCount++;
                            }
                        }
                        $cursor->addDay();
                    }
                }

                if ($request->ajax()) {
                    return new JsonResponse(['message' => "$createdCount turnos creados correctamente."], 201);
                }

                return redirect()->back()->with('success', "$createdCount turnos creados.");
            }

            // Legacy single recurrence support (if needed, or can be removed if frontend always sends shifts array)
            $request->validate([
                'doctor_id'  => ['required', 'integer', 'exists:doctors,id'],
                'start_date' => ['required', 'date'],
                'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
                'days'       => ['required', 'array'], // 0=Sun, 1=Mon... or 1=Mon? JS usually 0=Sun. Carbon dayOfWeek 0=Sun.
                'start_time' => ['required', 'date_format:H:i'],
                'end_time'   => ['required', 'date_format:H:i', 'after:start_time'],
            ]);

            $startDate = Carbon::parse($request->input('start_date'));
            $endDate = Carbon::parse($request->input('end_date'));
            $days = $request->input('days'); // Array of integers
            $createdCount = 0;

            $cursor = $startDate->copy();
            while ($cursor->lte($endDate)) {
                if (in_array($cursor->dayOfWeek, $days)) {
                    // Try create
                    $dateStr = $cursor->toDateString();

                    $conflict = DB::table('doctor_shifts')
                        ->where('doctor_id', $request->input('doctor_id'))
                        ->where('date', $dateStr)
                        ->where('start_time', '<', $request->input('end_time'))
                        ->where('end_time', '>', $request->input('start_time'))
                        ->exists();

                    if (! $conflict) {
                        $this->shiftRepository->create([
                            'doctor_id'  => $request->input('doctor_id'),
                            'date'       => $dateStr,
                            'start_time' => $request->input('start_time'),
                            'end_time'   => $request->input('end_time'),
                            'notes'      => $request->input('notes'),
                        ]);
                        $createdCount++;
                    }
                }
                $cursor->addDay();
            }

            if ($request->ajax()) {
                return new JsonResponse(['message' => "$createdCount turnos creados correctamente."], 201);
            }

            return redirect()->back()->with('success', "$createdCount turnos creados.");
        }

        $validated = $request->validate([
            'doctor_id'  => ['required', 'integer', 'exists:doctors,id'],
            'date'       => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time'   => ['required', 'date_format:H:i', 'after:start_time'],
            'notes'      => ['nullable', 'string'],
        ]);

        $conflict = DB::table('doctor_shifts')
            ->where('doctor_id', $validated['doctor_id'])
            ->where('date', $validated['date'])
            ->where('start_time', '<', $validated['end_time'])
            ->where('end_time', '>', $validated['start_time'])
            ->exists();

        if ($conflict) {
            if ($request->ajax()) {
                return new JsonResponse(['message' => 'Conflicto de horario detectado'], 422);
            }

            return redirect()->back()->withErrors(['start_time' => 'Conflicto de horario detectado'])->withInput();
        }

        $shift = $this->shiftRepository->create($validated);

        if ($request->ajax()) {
            return new JsonResponse([
                'id'         => $shift->id,
                'doctor_id'  => $shift->doctor_id,
                'date'       => $shift->date->toDateString(),
                'start_time' => substr($shift->start_time, 0, 5),
                'end_time'   => substr($shift->end_time, 0, 5),
                'notes'      => $shift->notes,
            ], 201);
        }

        session()->flash('success', 'Turno creado correctamente');

        return redirect()->route('admin.schedules.index');
    }

    public function edit(int $id): View
    {
        $shift = $this->shiftRepository->findOrFail($id);
        $doctors = $this->doctorRepository->all();

        return view('doctor::schedules.form', compact('shift', 'doctors'));
    }

    public function update(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'doctor_id'  => ['required', 'integer', 'exists:doctors,id'],
            'date'       => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time'   => ['required', 'date_format:H:i', 'after:start_time'],
            'notes'      => ['nullable', 'string'],
        ]);

        $conflict = DB::table('doctor_shifts')
            ->where('id', '!=', $id)
            ->where('doctor_id', $validated['doctor_id'])
            ->where('date', $validated['date'])
            ->where('start_time', '<', $validated['end_time'])
            ->where('end_time', '>', $validated['start_time'])
            ->exists();

        if ($conflict) {
            if ($request->ajax()) {
                return new JsonResponse(['message' => 'Conflicto de horario detectado'], 422);
            }

            return redirect()->back()->withErrors(['start_time' => 'Conflicto de horario detectado'])->withInput();
        }

        $shift = $this->shiftRepository->update($validated, $id);

        if ($request->ajax()) {
            return new JsonResponse([
                'id'         => $shift->id,
                'doctor_id'  => $shift->doctor_id,
                'date'       => $shift->date->toDateString(),
                'start_time' => substr($shift->start_time, 0, 5),
                'end_time'   => substr($shift->end_time, 0, 5),
                'notes'      => $shift->notes,
            ], 200);
        }

        session()->flash('success', 'Turno actualizado correctamente');

        return redirect()->route('admin.schedules.index');
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->shiftRepository->delete($id);

            return new JsonResponse(['message' => 'Turno eliminado correctamente'], 200);
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'No se pudo eliminar el turno'], 400);
        }
    }
}
