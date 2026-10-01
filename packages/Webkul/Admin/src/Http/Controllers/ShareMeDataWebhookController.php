<?php

namespace Webkul\Admin\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Admin\Services\ShareMeDataService;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Repositories\SpecialtyRepository;
use Webkul\Lead\Repositories\LeadRepository;

class ShareMeDataWebhookController extends Controller
{
    public function __construct(
        protected LeadRepository $leadRepository,
        protected ActivityRepository $activityRepository,
        protected PersonRepository $personRepository,
        protected DoctorRepository $doctorRepository,
        protected SpecialtyRepository $specialtyRepository,
        protected ShareMeDataService $shareMeDataService
    ) {}

    /**
     * Recibir citas desde ShareMeData (Webhook)
     */
    public function receive(Request $request)
    {
        Log::info('ShareMeData Webhook: Datos recibidos', $request->all());

        $data = $request->all();

        // Validaciones básicas del payload de SMD
        if (! isset($data['physician']['_id']) || ! isset($data['patient']['phone'])) {
            return response()->json(['message' => 'Datos incompletos'], 400);
        }

        try {
            return DB::transaction(function () use ($data) {
                // 1. Identificar o Crear Doctor
                $doctorExternalId = $data['physician']['_id'];
                $doctor = $this->doctorRepository->findOneByField('unique_id', $doctorExternalId);

                if (! $doctor) {
                    // Si no existe por unique_id, intentamos buscar por nombre (para evitar el error de unique name)
                    $doctorName = ($data['physician']['name'] ?? 'Doctor').' '.($data['physician']['lastName'] ?? '');
                    $doctorName = trim($doctorName) ?: 'Doctor Externo';
                    $doctorEmail = $data['physician']['email'] ?? null;

                    $doctor = $this->doctorRepository->findOneByField('name', $doctorName);

                    if ($doctor) {
                        // Si existe por nombre pero no tenía unique_id, lo actualizamos SIN borrar el nombre
                        $updateData = [
                            'unique_id' => $doctorExternalId,
                            'name'      => $doctorName,
                        ];

                        if ($doctorEmail && \Illuminate\Support\Facades\Schema::hasColumn('doctors', 'email')) {
                            $updateData['email'] = $doctorEmail;
                        }

                        $this->doctorRepository->update($updateData, $doctor->id);
                        $doctor = $this->doctorRepository->find($doctor->id);
                    } else {
                        // Si realmente no existe, lo creamos
                        $createData = [
                            'name'      => $doctorName,
                            'unique_id' => $doctorExternalId,
                            'is_active' => true,
                        ];

                        if ($doctorEmail && \Illuminate\Support\Facades\Schema::hasColumn('doctors', 'email')) {
                            $createData['email'] = $doctorEmail;
                        }

                        $doctor = $this->doctorRepository->create($createData);
                        Log::info('Webhook SMD: Doctor creado automáticamente', ['id' => $doctor->id]);
                    }
                } else {
                    // Si ya existe, nos aseguramos de que el email esté actualizado si viene en el payload
                    $doctorEmail = $data['physician']['email'] ?? null;
                    if ($doctorEmail && \Illuminate\Support\Facades\Schema::hasColumn('doctors', 'email') && $doctor->email !== $doctorEmail) {
                        $this->doctorRepository->update(['email' => $doctorEmail], $doctor->id);
                        $doctor = $this->doctorRepository->find($doctor->id);
                    }
                }

                // 2. Gestionar Especialidad (Automatización del Punto 3)
                $specialtyName = $data['specialty'] ?? 'General';
                $specialty = $this->specialtyRepository->fetchOrCreateByName($specialtyName);
                if (! $doctor->specialties->contains($specialty->id)) {
                    $doctor->specialties()->attach($specialty->id);
                }

                // 3. Identificar o Crear Paciente (Validar por email generado del teléfono)
                $phone = $data['patient']['phone'];
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

                // Si el número no tiene el prefijo 591, se lo agregamos
                if (! str_starts_with($cleanPhone, '591')) {
                    $cleanPhone = '591'.$cleanPhone;
                }

                $kommoEmail = $cleanPhone.'@s.kommo-whatsapp.net';

                // Buscamos si existe una prospecto con ese email de Kommo
                $person = $this->personRepository
                    ->whereJsonContains('emails', [['value' => $kommoEmail]])
                    ->first();

                if (! $person) {
                    // Búsqueda alternativa por teléfono si no existe por email
                    $person = $this->personRepository
                        ->whereJsonContains('contact_numbers', [['value' => $phone]])
                        ->orWhereJsonContains('contact_numbers', [['value' => (string) $phone]])
                        ->first();
                }

                if ($person && is_object($person) && ! ($person instanceof \Webkul\Contact\Contracts\Person)) {
                    $person = $this->personRepository->find($person->id);
                }

                if (! $person) {
                    $person = $this->personRepository->create([
                        'name'            => ($data['patient']['name'] ?? 'Paciente').' '.($data['patient']['lastName'] ?? 'Externo'),
                        'emails'          => [['value' => $kommoEmail, 'label' => 'work']],
                        'contact_numbers' => [['value' => $phone, 'label' => 'work']],
                        'entity_type'     => 'persons',
                    ]);
                    $personId = $person->id;
                } else {
                    $personId = $person->id;
                }

                // 4. Crear Lead
                $leadTitle = ($person->name ?? (($data['patient']['name'] ?? 'Paciente').' '.($data['patient']['lastName'] ?? ''))).' - '.($specialtyName);

                $leadData = [
                    'title'       => $leadTitle,
                    'description' => $data['summary'] ?? 'Cita sincronizada desde ShareMeData',
                    'entity_type' => 'leads',
                    'person'      => ['id' => $personId],
                ];

                // Solo agregamos doctor_id si la columna existe en la tabla leads
                if (\Illuminate\Support\Facades\Schema::hasColumn('leads', 'doctor_id')) {
                    $leadData['doctor_id'] = $doctor->id;
                }

                $lead = $this->leadRepository->create($leadData);

                // 5. Crear Actividad (Cita)
                $scheduleFrom = Carbon::parse($data['slot']['start']);
                $scheduleTo = Carbon::parse($data['slot']['end']);

                $activity = $this->activityRepository->create([
                    'type'          => 'meeting',
                    'title'         => $leadTitle,
                    'comment'       => $data['summary'] ?? 'Sincronizado vía Webhook',
                    'schedule_from' => $scheduleFrom->format('Y-m-d H:i:s'),
                    'schedule_to'   => $scheduleTo->format('Y-m-d H:i:s'),
                    'user_id'       => 1, // Usuario admin por defecto o sistema
                    'participants'  => [
                        'doctors' => [$doctor->id],
                        'persons' => [$personId],
                    ],
                    'additional'    => json_encode([
                        'lead_id'     => $lead->id,
                        'external_id' => $data['_id'] ?? null, // ID del evento en SMD
                        'source'      => 'ShareMeData',
                    ]),
                ]);

                $activity->leads()->sync([$lead->id]);

                Log::info('Webhook SMD: Cita procesada correctamente', [
                    'lead_id'     => $lead->id,
                    'activity_id' => $activity->id,
                ]);

                return response()->json([
                    'success'     => true,
                    'lead_id'     => $lead->id,
                    'activity_id' => $activity->id,
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error('ShareMeData Webhook: Error procesando cita', [
                'error' => $e->getMessage(),
                'data'  => $data,
            ]);

            return response()->json(['message' => 'Error interno'], 500);
        }
    }
}
