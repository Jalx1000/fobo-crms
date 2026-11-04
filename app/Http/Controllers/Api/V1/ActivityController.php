<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Activity\Repositories\FileRepository;
use Webkul\Admin\Exceptions\AppointmentException;
use Webkul\Admin\Services\AppointmentService;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\RestApi\Http\Controllers\V1\Activity\ActivityController as BaseActivityController;

class ActivityController extends BaseActivityController
{
    public function __construct(
        ActivityRepository         $activityRepository,
        FileRepository             $fileRepository,
        LeadRepository             $leadRepository,
        protected AppointmentService $appointmentService,
    ) {
        parent::__construct($activityRepository, $fileRepository, $leadRepository);
    }

    /**
     * Crea una nueva actividad.
     * Para type=meeting aplica las validaciones de jornada laboral y disponibilidad en ShareMeData.
     */
    public function store(): JsonResponse
    {
        $this->validate(request(), [
            'type'          => 'required',
            'comment'       => 'required_if:type,note',
            'schedule_from' => 'required_unless:type,note,file',
            'schedule_to'   => 'required_unless:type,note,file',
            'file'          => 'required_if:type,file',
        ]);

        if (request('type') !== 'meeting') {
            return parent::store();
        }

        // Para meetings: extraer doctor y paciente de participants[]
        $doctorId = request()->input('participants.doctors.0');
        $personId = request()->input('participants.persons.0');

        if (! $doctorId || ! $personId) {
            return response()->json([
                'message' => 'Para agendar una consulta debes seleccionar al menos un Doctor y un Paciente.',
            ], 422);
        }

        // Validaciones exclusivas de meeting. product_id (servicio), zone_id (zona
        // del servicio) y price (precio pactado) son opcionales pero, si vienen,
        // deben existir / ser válidos. La zona debe pertenecer al servicio.
        $this->validate(request(), [
            'product_id' => 'nullable|integer|exists:products,id',
            'zone_id'    => 'nullable|integer|exists:service_zones,id',
            'price'      => 'nullable|numeric|min:0',
        ]);

        try {
            $result = $this->appointmentService->process([
                'doctor_id'     => $doctorId,
                'person'        => ['id' => $personId],
                'schedule_from' => request('schedule_from'),
                'schedule_to'   => request('schedule_to'),
                'title'         => request('title'),
                'reason'        => request('comment'),
                'product_id'    => request('product_id'),
                'zone_id'       => request('zone_id'),
                'price'         => request('price'),
                'lead_id'       => request('lead_id'),
            ]);

            return response()->json([
                'data'    => $result,
                'message' => $result['message'],
            ]);
        } catch (AppointmentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'details' => $e->getDetails(),
            ], 422);
        }
    }
}
