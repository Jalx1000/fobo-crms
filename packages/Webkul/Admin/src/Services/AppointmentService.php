<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Admin\Exceptions\AppointmentException;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Doctor\Repositories\DoctorRepository;
use Webkul\Doctor\Repositories\SpecialtyRepository;
use Webkul\Lead\Repositories\LeadRepository;

class AppointmentService
{
    public function __construct(
        protected ActivityRepository $activityRepository,
        protected LeadRepository $leadRepository,
        protected PersonRepository $personRepository,
        protected ShareMeDataService $shareMeDataService,
        protected DoctorRepository $doctorRepository,
        protected SpecialtyRepository $specialtyRepository,
    ) {}

    /**
     * Valida y crea una cita médica de forma unificada.
     *
     * Orden garantizado:
     *  1. Validar turno local (doctor_shifts)
     *  2. Validar conflicto local (doctor_activities) — un Lead cancelado no bloquea
     *  3. Descubrir unique_id del doctor en SMD si falta            ┐
     *  4. Verificar disponibilidad en ShareMeData                   │ se omiten si
     *  5. Crear evento en ShareMeData PRIMERO                       ┘ smd.validate_availability=false
     *  6. Crear registros locales (Lead + Activity) en transacción
     *
     * @param  array  $data  {
     *                       doctor_id, person.id, schedule_from, schedule_to,
     *                       title?, reason?, product_id?, lead_id?
     *                       }
     * @return array { lead_id, activity_id, message }
     *
     * @throws AppointmentException
     */
    public function process(array $data): array
    {
        $scheduleFrom = Carbon::parse($data['schedule_from']);
        $scheduleTo = Carbon::parse($data['schedule_to']);
        $doctorId = $data['doctor_id'];
        // Multi-doctor: el primero es el "principal" (valida turno/disponibilidad y
        // crea el evento en SMD). Los demás se adjuntan a la cita local y se suman al
        // evento de SMD por PATCH (el endpoint createEvent solo acepta un físico).
        $allDoctorIds = ! empty($data['doctor_ids'])
            ? array_values(array_unique(array_map('intval', $data['doctor_ids'])))
            : [(int) $doctorId];
        // Garantizar que el principal quede primero.
        $allDoctorIds = array_values(array_unique(array_merge([(int) $doctorId], $allDoctorIds)));
        $personData = $data['person'];
        $productId = $data['product_id'] ?? null;
        // Regla 1 lead = 1 cita: cada cita crea su propio lead. Solo se reusa un
        // lead cuando llega un lead_id EXPLÍCITO (ej. agendar desde un lead).
        // (Antes se auto-reusaba el lead abierto más reciente del paciente, lo que
        // dejaba varios appointments colgando de un mismo lead.)
        $existingLeadId = $data['lead_id'] ?? null;

        // Si no viene product_id pero sí lead_id, intentar tomarlo del primer producto del lead
        if (! $productId && $existingLeadId) {
            $leadProduct = DB::table('lead_products')
                ->where('lead_id', $existingLeadId)
                ->orderBy('id')
                ->value('product_id');
            $productId = $leadProduct ?: null;
        }

        $doctor = DB::table('doctors')->where('id', $doctorId)->first();

        if (! $doctor) {
            throw new AppointmentException("Doctor con ID {$doctorId} no encontrado.");
        }

        $doctorExternalId = $doctor->unique_id;
        $doctorEmail = $doctor->email;

        // ── 1. Validar turno/jornada laboral ─────────────────────────────────
        $hasValidShift = DB::table('doctor_shifts')
            ->where('doctor_id', $doctorId)
            ->where('date', $scheduleFrom->toDateString())
            ->where('start_time', '<=', $scheduleFrom->format('H:i'))
            ->where('end_time', '>=', $scheduleTo->format('H:i'))
            ->exists();

        if (! $hasValidShift) {
            throw new AppointmentException(
                'El horario seleccionado está fuera de la jornada laboral del doctor para este día. '.
                'Por favor, revisa los turnos disponibles.'
            );
        }

        // ── 2. Validar conflicto local ────────────────────────────────────────
        // Una Activity cuyo(s) Lead(s) están todos cerrados/cancelados no debe
        // bloquear el horario para una cita nueva. "Cancelado" se detecta de dos
        // formas, porque en este CRM la cancelación manual desde el pipeline solo
        // mueve el stage (no necesariamente toca leads.status):
        //   - leads.status = 0 (mecanismo estándar de Krayin, usado por cancelDropbox())
        //   - leads.lead_pipeline_stage_id está en stage_map.cancelled/no_show (config/smd.php)
        // Si la Activity no tiene Lead vinculado, se sigue tratando como conflicto
        // (comportamiento conservador ante datos inconsistentes).
        $cancelledStageIds = array_filter([
            (int) config('smd.stage_map.cancelled'),
            (int) config('smd.stage_map.no_show'),
        ]);

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
            ->where(function ($query) use ($cancelledStageIds) {
                $query->whereNotExists(function ($sub) {
                    $sub->select('lead_activities.activity_id')
                        ->from('lead_activities')
                        ->whereColumn('lead_activities.activity_id', 'activities.id');
                })->orWhereExists(function ($sub) use ($cancelledStageIds) {
                    $sub->select('lead_activities.activity_id')
                        ->from('lead_activities')
                        ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
                        ->whereColumn('lead_activities.activity_id', 'activities.id')
                        ->where(function ($q) {
                            $q->whereNull('leads.status')->orWhere('leads.status', '!=', 0);
                        })
                        ->where(function ($q) use ($cancelledStageIds) {
                            // NULL stage_id no es "cancelado": en SQL "NULL NOT IN (...)" da
                            // NULL (no true), así que hay que tratarlo aparte para que un lead
                            // sin stage asignado siga contando como abierto/bloqueante.
                            $q->whereNull('leads.lead_pipeline_stage_id')
                                ->orWhereNotIn('leads.lead_pipeline_stage_id', $cancelledStageIds);
                        });
                });
            })
            ->exists();

        if ($localConflict) {
            throw new AppointmentException(
                'El doctor ya tiene una cita programada en este horario en el sistema local.'
            );
        }

        // ── Resolver paciente y producto (siempre, sean locales) ─────────────
        $person = $this->personRepository->find($personData['id'] ?? null);

        if (! $person) {
            throw new AppointmentException("Paciente con ID {$personData['id']} no encontrado.");
        }

        $product = $productId ? DB::table('products')->where('id', $productId)->first() : null;

        $nameParts = explode(' ', trim($person->name ?? 'Paciente'));
        $eventTitle = ($person->name ?? 'Paciente').' - '.($product->name ?? 'Consulta');
        $title = $data['title'] ?? $eventTitle;

        $validateWithSmd = (bool) config('smd.validate_availability', true);
        $smdResult = null;

        if ($validateWithSmd) {
            // ── 3. Auto-descubrir unique_id en SMD si falta ──────────────────
            if (empty($doctorExternalId) || empty($doctorEmail)) {
                $doctorModel = $this->doctorRepository->with('specialties')->find($doctorId);
                $discoverySpecialties = $doctorModel->specialties->pluck('name')->toArray();

                if (empty($discoverySpecialties)) {
                    $discoverySpecialties = $this->specialtyRepository->all()->pluck('name')->toArray();
                }

                if (empty($discoverySpecialties)) {
                    $discoverySpecialties = ['General'];
                }

                $found = false;
                foreach ($discoverySpecialties as $spec) {
                    $this->shareMeDataService->checkAvailability(
                        null, $spec, 'Santa Cruz',
                        $scheduleFrom->format('Y-m-d H:i:s'),
                        $scheduleTo->format('Y-m-d H:i:s')
                    );
                    $raw = $this->shareMeDataService->getLastResponse();

                    if ($raw && isset($raw['body']) && is_array($raw['body'])) {
                        foreach ($raw['body'] as $item) {
                            $smdName = trim(strtolower(
                                ($item['physician']['name'] ?? '').' '.
                                ($item['physician']['lastName'] ?? '')
                            ));
                            if ($smdName === trim(strtolower($doctor->name))) {
                                $doctorExternalId = $item['physician']['_id'] ?? null;
                                $doctorEmail = $item['physician']['email'] ?? null;
                                if ($doctorExternalId) {
                                    $this->doctorRepository->update(
                                        ['unique_id' => $doctorExternalId, 'email' => $doctorEmail],
                                        $doctor->id
                                    );
                                    $found = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }

                if (! $found) {
                    throw new AppointmentException(
                        "No se pudo vincular al doctor {$doctor->name} con ShareMeData. ".
                        'Verifica que el nombre coincida exactamente.'
                    );
                }
            }

            // ── 4. Verificar disponibilidad en ShareMeData ────────────────────
            $doctorModel = $this->doctorRepository->with('specialties')->find($doctorId);
            $doctorSpecialties = $doctorModel->specialties->pluck('name')->toArray();

            if (empty($doctorSpecialties)) {
                $doctorSpecialties = $this->specialtyRepository->all()->pluck('name')->toArray();
            }

            if (empty($doctorSpecialties)) {
                $doctorSpecialties = ['General'];
            }

            $isAvailableExternally = false;
            $smdErrors = [];

            foreach ($doctorSpecialties as $specialty) {
                $slots = $this->shareMeDataService->checkAvailability(
                    $doctorExternalId, $specialty, 'Santa Cruz',
                    $scheduleFrom->format('Y-m-d H:i:s'),
                    $scheduleTo->format('Y-m-d H:i:s')
                );
                $lastResponse = $this->shareMeDataService->getLastResponse();

                if (! empty($slots)) {
                    $requiredIntervals = [];
                    $current = $scheduleFrom->copy();
                    while ($current->lessThan($scheduleTo)) {
                        $requiredIntervals[] = [
                            'start' => $current->timestamp,
                            'end'   => $current->copy()->addMinutes(15)->timestamp,
                        ];
                        $current->addMinutes(15);
                    }

                    $foundCount = 0;
                    foreach ($requiredIntervals as $required) {
                        foreach ($slots as $daySlots) {
                            foreach ($daySlots as $date => $intervals) {
                                foreach ($intervals as $interval) {
                                    if (
                                        Carbon::parse($interval['start'])->timestamp === $required['start'] &&
                                        Carbon::parse($interval['end'])->timestamp === $required['end']
                                    ) {
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
                    }

                    $smdErrors[$specialty] = "Solo se encontraron {$foundCount} de ".count($requiredIntervals).' bloques de 15m libres.';
                } else {
                    $smdErrors[$specialty] = $lastResponse['body']['message'] ?? 'Sin disponibilidad devuelta por SMD';
                }
            }

            Log::debug('[SMD-DEBUG] Resultado check disponibilidad', [
                'doctor_id'          => $doctorId,
                'doctor_external_id' => $doctorExternalId,
                'schedule_from'      => $scheduleFrom->format('Y-m-d H:i:s'),
                'schedule_to'        => $scheduleTo->format('Y-m-d H:i:s'),
                'schedule_from_tz'   => $scheduleFrom->setTimezone(config('app.timezone', 'America/La_Paz'))->format('Y-m-d\TH:i:sP'),
                'schedule_to_tz'     => $scheduleTo->setTimezone(config('app.timezone', 'America/La_Paz'))->format('Y-m-d\TH:i:sP'),
                'is_available'       => $isAvailableExternally,
                'smd_errors'         => $smdErrors,
                'specialties_tried'  => $doctorSpecialties,
            ]);

            if (! $isAvailableExternally) {
                throw new AppointmentException(
                    'El doctor no tiene disponibilidad en SHAREMEDATA para el horario solicitado.',
                    ['smd_errors' => $smdErrors, 'doctor_external_id' => $doctorExternalId]
                );
            }

            // ── 5. Crear evento en ShareMeData PRIMERO ────────────────────────
            $personPhone = '0';
            if (! empty($person->contact_numbers)) {
                $contactNumbers = is_array($person->contact_numbers)
                    ? $person->contact_numbers
                    : json_decode($person->contact_numbers, true);
                if (is_array($contactNumbers) && ! empty($contactNumbers[0]['value'])) {
                    $personPhone = (string) $contactNumbers[0]['value'];
                }
            }

            // ── 4.5 Sincronizar paciente con SMD para obtener personID ────────
            $smdPersonId = $person->smd_patient_id ?? null;

            if (! $smdPersonId && $personPhone !== '0') {
                $smdPatients = $this->shareMeDataService->searchPatient($personPhone);

                if (! empty($smdPatients)) {
                    $smdPersonId = $smdPatients[0]['_id'] ?? null;
                } else {
                    $nameParts_ = explode(' ', trim($person->name ?? 'Paciente'));
                    $ciAttr_ = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
                        ->findOneByField('code', 'ci_paciente');
                    $ci_ = $ciAttr_ ? $person->getCustomAttributeValue($ciAttr_) : null;

                    $result_ = $this->shareMeDataService->createPatient([
                        'first_name' => $nameParts_[0] ?? 'Paciente',
                        'last_name'  => implode(' ', array_slice($nameParts_, 1)) ?: 'Externo',
                        'phone'      => $personPhone,
                        'ci'         => $ci_ ?: null,
                    ]);

                    if ($result_['success']) {
                        $smdPersonId = $result_['data']['_id'] ?? null;
                    } elseif ($result_['duplicate'] ?? false) {
                        $retry_ = $this->shareMeDataService->searchPatient($personPhone);
                        $smdPersonId = $retry_[0]['_id'] ?? null;
                    }
                }

                if ($smdPersonId) {
                    DB::table('persons')->where('id', $person->id)->update(['smd_patient_id' => $smdPersonId]);
                    $person = $this->personRepository->find($person->id);
                }
            }

            $firstName = $nameParts[0] ?: 'Paciente';
            $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'Externo';

            $smdPayload = [
                'summary'   => 'CONSULTA: '.$title,
                'physician' => ['_id' => $doctorExternalId, 'email' => $doctorEmail ?: ''],
                'patient'   => [
                    'name'     => (string) $firstName,
                    'lastName' => (string) $lastName,
                    'phone'    => (string) $personPhone,
                    'personID' => (string) ($smdPersonId ?? ''),
                    'birthday' => '',
                ],
                'slot' => [
                    'start' => $scheduleFrom->setTimezone(config('app.timezone', 'America/La_Paz'))->format('Y-m-d\TH:i:sP'),
                    'end'   => $scheduleTo->setTimezone(config('app.timezone', 'America/La_Paz'))->format('Y-m-d\TH:i:sP'),
                ],
            ];

            Log::debug('[SMD-DEBUG] Payload enviado a createEvent', [
                'payload' => $smdPayload,
            ]);

            $smdResult = $this->shareMeDataService->createEvent($smdPayload);

            if (! ($smdResult['success'] ?? false)) {
                Log::warning('[SMD] createEvent FALLÓ', [
                    'patient_phone_suffix' => '***'.substr($smdPayload['patient']['phone'] ?? '', -4),
                    'physician_id'         => $smdPayload['physician']['_id'] ?? null,
                    'slot'                 => $smdPayload['slot'] ?? null,
                    'smd_status'           => $smdResult['status'] ?? null,
                    'smd_message'          => $smdResult['message'] ?? null,
                ]);

                throw new AppointmentException(
                    'Error al registrar la cita en ShareMeData. La cita no fue creada.',
                    ['smd_response' => $smdResult]
                );
            }

            // ── 5.b Multi-doctor: sumar los físicos extra al evento por PATCH ──
            // createEvent solo acepta un físico; el resto se agrega editando el
            // evento recién creado (mismo mecanismo que la edición desde el CRM).
            if (count($allDoctorIds) > 1) {
                $this->attachExtraPhysicians(
                    $smdResult['data'] ?? [],
                    $allDoctorIds,
                    $smdPersonId ?? null,
                    $firstName,
                    $lastName,
                    $personPhone,
                    $scheduleFrom,
                    $scheduleTo,
                    $title,
                    $data['reason'] ?? ''
                );
            }
        } else {
            Log::info('[AppointmentService] Creando cita sin validar SMD (smd.validate_availability=false)', [
                'doctor_id' => $doctorId,
                'person_id' => $person->id,
            ]);
        }

        // ── 6. Crear registros locales en transacción ────────────────────────
        try {
            return DB::transaction(function () use (
                $data, $scheduleFrom, $scheduleTo,
                $doctor, $allDoctorIds,
                $person, $productId, $product, $title,
                $existingLeadId, $smdResult, $validateWithSmd
            ) {
                if ($existingLeadId) {
                    $lead = $this->leadRepository->find($existingLeadId);
                    if (! $lead) {
                        throw new AppointmentException("Lead con ID {$existingLeadId} no encontrado.");
                    }
                } else {
                    $leadData = [
                        'title'       => $title,
                        'description' => $data['reason'] ?? '',
                        'entity_type' => 'leads',
                        'person'      => ['id' => $person->id],
                    ];
                    if (\Illuminate\Support\Facades\Schema::hasColumn('leads', 'doctor_id')) {
                        $leadData['doctor_id'] = $doctor->id;
                    }
                    $lead = $this->leadRepository->create($leadData);
                }

                $currentUserId = auth()->guard('user')->id() ?? auth()->id() ?? 1;

                $activity = $this->activityRepository->create([
                    'type'          => 'meeting',
                    'title'         => $title,
                    'comment'       => $data['reason'] ?? '',
                    'schedule_from' => $scheduleFrom->format('Y-m-d H:i:s'),
                    'schedule_to'   => $scheduleTo->format('Y-m-d H:i:s'),
                    'user_id'       => $currentUserId,
                    'participants'  => ['doctors' => $allDoctorIds, 'persons' => [$person->id]],
                ]);

                $activity->leads()->sync([$lead->id]);

                // Mover el lead al stage "Cita confirmadas" buscado por nombre para no depender del ID
                $confirmedStageId = DB::table('lead_pipeline_stages')
                    ->where('lead_pipeline_id', DB::table('leads')->where('id', $lead->id)->value('lead_pipeline_id'))
                    ->where(function ($q) {
                        $q->where('name', 'like', '%confirm%')
                            ->orWhere('name', 'like', '%Confirm%');
                    })
                    ->value('id') ?? 7;

                DB::table('leads')->where('id', $lead->id)->update(['lead_pipeline_stage_id' => $confirmedStageId]);

                if ($productId) {
                    $activity->products()->sync([$productId]);
                }

                // Vincular la cita con el evento de SMD (external_id) para que EDITARLA
                // luego pueda empujar los cambios por PATCH (pushAppointmentToSmd busca
                // este registro). Sin esto, una cita creada en el CRM no se puede
                // sincronizar hacia SMD al editarla. Idempotente por external_id.
                if ($validateWithSmd && ! empty($smdResult['data'])) {
                    $smdEvent = $this->extractSmdEvent($smdResult['data']);

                    if (! empty($smdEvent['_id'])) {
                        DB::table('smd_synced_events')->updateOrInsert(
                            ['external_id' => $smdEvent['_id']],
                            [
                                'source_file'       => 'crm',
                                'activity_id'       => $activity->id,
                                'lead_id'           => $lead->id,
                                'raw_payload'       => json_encode($smdEvent['event'], JSON_UNESCAPED_UNICODE),
                                'status'            => (string) ($smdEvent['event']['status'] ?? config('smd.smd_status.confirmed', 'CONFIRMED')),
                                'source_updated_at' => now(),
                                'processed_at'      => now(),
                                'updated_at'        => now(),
                                'created_at'        => now(),
                            ]
                        );
                    }
                }

                Log::info('Cita creada', [
                    'activity_id' => $activity->id,
                    'lead_id'     => $lead->id,
                    'smd_event'   => $smdResult['data'] ?? null,
                ]);

                return [
                    'lead_id'      => $lead->id,
                    'activity_id'  => $activity->id,
                    'product_id'   => $productId,
                    'product_name' => $product->name ?? null,
                    'message'      => $validateWithSmd
                        ? 'Cita creada y sincronizada correctamente.'
                        : 'Cita creada localmente. No se validó disponibilidad en ShareMeData (smd.validate_availability=false).',
                ];
            });
        } catch (AppointmentException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error creando cita localmente tras registro en SMD: '.$e->getMessage(), [
                'smd_event' => $smdResult['data'] ?? null,
            ]);
            throw new AppointmentException(
                'La cita fue registrada en ShareMeData pero ocurrió un error al guardarla localmente. '.
                'Contacta al administrador.',
                ['internal_error' => $e->getMessage()]
            );
        }
    }

    /**
     * Suma los físicos adicionales a un evento recién creado en SMD, mediante PATCH.
     *
     * El endpoint createEvent solo admite un físico, así que para citas multi-doctor
     * se crea con el principal y luego se edita el evento agregando el resto como
     * attendances de tipo physician. Nunca lanza: si falla, la cita queda con el
     * doctor principal en SMD (y todos los doctores localmente) y se registra un aviso.
     *
     * @param  array  $createdEvent  Respuesta de createEvent (contiene el _id del evento).
     * @param  int[]  $allDoctorIds  Ids locales de todos los doctores (principal primero).
     */
    private function attachExtraPhysicians(
        array $createdEvent,
        array $allDoctorIds,
        ?string $smdPersonId,
        string $firstName,
        string $lastName,
        string $personPhone,
        Carbon $scheduleFrom,
        Carbon $scheduleTo,
        string $title,
        string $reason
    ): void {
        // El _id del evento creado viene bajo `payload` en la respuesta de createEvent.
        $extracted = $this->extractSmdEvent($createdEvent);
        $smdEventId = $extracted['_id'];
        $eventId = $extracted['eventId'];

        if (! $smdEventId) {
            Log::warning('[SMD] Multi-doctor: no se pudo obtener el _id del evento creado; los físicos extra no se agregaron.', [
                'created_event_keys' => array_keys($createdEvent),
                'created_event_json' => json_encode($createdEvent, JSON_UNESCAPED_UNICODE),
            ]);

            return;
        }

        $attendances = [];

        // Paciente.
        if ($smdPersonId) {
            $attendances[] = [
                '_id'      => $smdPersonId,
                'name'     => $firstName,
                'lastName' => $lastName,
                'type'     => 'patient',
                'phone'    => $personPhone === '0' ? '' : $personPhone,
            ];
        }

        // Todos los físicos (por su unique_id = _id de SMD). Un doctor sin unique_id
        // no se puede vincular a SMD: se omite del evento (queda local igual).
        $doctors = DB::table('doctors')->whereIn('id', $allDoctorIds)->get();

        foreach ($doctors as $doc) {
            if (empty($doc->unique_id)) {
                Log::warning('[SMD] Multi-doctor: doctor sin unique_id, no se agregó a SMD.', [
                    'doctor_id' => $doc->id, 'name' => $doc->name,
                ]);

                continue;
            }

            $parts = preg_split('/\s+/', trim((string) $doc->name), 2);

            $attendances[] = [
                '_id'      => $doc->unique_id,
                'name'     => $parts[0] ?? '',
                'lastName' => $parts[1] ?? '',
                'type'     => 'physician',
                'phone'    => '',
            ];
        }

        $body = [
            '_id'         => $smdEventId,
            'eventId'     => $eventId,
            'type'        => 'event',
            'summary'     => 'CONSULTA: '.$title,
            'description' => (string) $reason,
            'startDate'   => $scheduleFrom->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'endDate'     => $scheduleTo->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'attendances' => $attendances,
            'status'      => (string) config('smd.smd_status.confirmed', 'CONFIRMED'),
        ];

        $result = $this->shareMeDataService->updateEvent((string) $smdEventId, $body);

        if (! ($result['success'] ?? false)) {
            Log::warning('[SMD] Multi-doctor: PATCH para sumar físicos extra falló.', [
                'event_id' => $smdEventId,
                'message'  => $result['message'] ?? null,
            ]);
        } else {
            Log::info('[SMD] Multi-doctor: físicos extra agregados al evento.', [
                'event_id'   => $smdEventId,
                'physicians' => count(array_filter($attendances, fn ($a) => ($a['type'] ?? '') === 'physician')),
            ]);
        }
    }

    /**
     * Extrae el evento y su identificador de la respuesta de createEvent.
     *
     * SMD devuelve `{message, payload, physician}`, con el evento (que trae `_id`
     * y `eventId`) bajo `payload`. Se contemplan otras envolturas por robustez.
     *
     * @return array{event: array, _id: ?string, eventId: ?string}
     */
    private function extractSmdEvent(array $created): array
    {
        $evt = $created['payload']
            ?? $created['data']
            ?? $created['event']
            ?? $created;
        $evt = is_array($evt) ? $evt : [];

        return [
            'event'   => $evt,
            '_id'     => $evt['_id'] ?? ($created['_id'] ?? null) ?? ($evt['event']['_id'] ?? null),
            'eventId' => $evt['eventId'] ?? ($created['eventId'] ?? null) ?? ($evt['event']['eventId'] ?? null),
        ];
    }
}
