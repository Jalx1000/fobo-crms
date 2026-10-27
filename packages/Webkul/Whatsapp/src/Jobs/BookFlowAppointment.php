<?php

namespace Webkul\Whatsapp\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Admin\Exceptions\AppointmentException;
use Webkul\Admin\Services\AppointmentService;
use Webkul\Admin\Services\BookingOptions;
use Webkul\Admin\Services\InsuranceService;
use Webkul\Admin\Services\PatientAppointments;
use Webkul\Admin\Services\PatientIdentityResolver;
use Webkul\Whatsapp\Jobs\Concerns\RepliesToConversation;

/**
 * Crea la cita pedida por el formulario de WhatsApp y le avisa a quien la pidió.
 *
 * Va en segundo plano porque crearla (validar en vivo con SMD, crear el evento,
 * completar el paciente por PATCH) tarda más de lo que Meta espera la respuesta
 * del endpoint del Flow: el usuario veía "Error en la respuesta del punto de
 * conexión" aunque la cita se había creado. Ahora el formulario cierra al
 * instante con "estamos confirmando" y el resultado llega por el chat.
 */
class BookFlowAppointment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RepliesToConversation, SerializesModels;

    /** Una sola vez: reintentar podría crear la cita dos veces en SMD. */
    public int $tries = 1;

    /** SMD (~10 s) + las aseguradoras (hasta ~50 s si alguna no responde). */
    public int $timeout = 180;

    /**
     * @param  array{conversation_id:int, responsible_id:int, patient_id:int, doctor_id:int, specialty_id:int, specialty:string, date:string, time:string, duration:int}  $booking
     */
    public function __construct(public array $booking)
    {
        $this->requestedAt = time();
    }

    public function handle(AppointmentService $appointments, BookingOptions $catalog, PatientAppointments $patients, InsuranceService $insurance): void
    {
        $b = $this->booking;

        // Paciente nuevo del formulario ("Otra persona"): se da de alta acá y no
        // en el formulario, porque el alta lo registra en SMD y tarda más de lo
        // que Meta espera la respuesta de la pantalla.
        if (! empty($b['pending_patient'])) {
            $created = app(PatientIdentityResolver::class)->commit(
                (int) $b['responsible_id'],
                $b['pending_patient']['input'],
                'whatsapp_flow',
                $b['pending_patient']['found'] ?? null,
            );

            if (empty($created['patient_id'])) {
                Log::warning('[WhatsAppFlow] No se pudo dar de alta al paciente del formulario', ['estado' => $created['status'] ?? null]);
                $this->reply('No pudimos registrar los datos del paciente para agendar. Escribinos y te ayudamos. 🙏');

                return;
            }

            $b['patient_id'] = (int) $created['patient_id'];
        }

        $from = Carbon::parse($b['date'].' '.$b['time'], config('app.timezone'));
        $doctor = trim((string) DB::table('doctors')->where('id', $b['doctor_id'])->value('name'));
        $patient = trim((string) DB::table('persons')->where('id', $b['patient_id'])->value('name'));
        $when = ucfirst($from->copy()->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm'));

        // Antes de crear la cita: crearla da de alta al paciente en SMD.
        $reason = 'Agendada por WhatsApp (formulario) - '.$b['specialty'];

        if ($patients->isNewPatient((int) $b['patient_id'])) {
            $reason = 'PACIENTE NUEVO - '.$reason;
        }

        // Seguro: con la cédula que pidió el formulario, contra todas las
        // aseguradoras. Si falla, se agenda igual: recepción lo verifica después.
        // Carnet corregido en "Revisá tu cita": se guarda antes de verificar con él.
        if (! empty($b['ci_override'])) {
            app(PatientIdentityResolver::class)->replaceCi((int) $b['patient_id'], (string) $b['ci_override']);
        }

        $coverage = $this->checkInsurance($insurance, (int) $b['patient_id']);
        $reason .= ' - Seguro: '.$coverage['reason'];

        try {
            $result = $appointments->process([
                'doctor_id'     => (int) $b['doctor_id'],
                'doctor_ids'    => [(int) $b['doctor_id']],
                'specialty_id'  => (int) ($b['specialty_id'] ?? 0) ?: null,
                'person'        => ['id' => (int) $b['patient_id']],
                'schedule_from' => $from->format('Y-m-d H:i:s'),
                'schedule_to'   => $from->copy()->addMinutes((int) $b['duration'])->format('Y-m-d H:i:s'),
                'reason'        => $reason,
                // Paciente sin teléfono propio (tercero): SMD lo registra con el
                // número de quien agendó, que es a quien se le avisa.
                'contact_phone' => $this->contactPhone((int) $b['responsible_id'], (int) $b['conversation_id']),
                'lead_id'       => $this->consultaLead((int) $b['responsible_id'], (int) $b['patient_id']),
            ]);
        } catch (AppointmentException $e) {
            Log::info('[WhatsAppFlow] No se pudo confirmar la cita pedida por formulario', [
                'doctor_id' => $b['doctor_id'], 'fecha' => $from->toDateTimeString(), 'motivo' => $e->getMessage(),
            ]);

            // Ya tiene cita ese día con ese doctor (p.ej. mandó el formulario dos
            // veces): decirlo, no "el horario no está disponible".
            $details = $e->getDetails();

            if (($details['code'] ?? null) === 'patient_already_booked') {
                $existing = ucfirst(\Carbon\Carbon::parse($details['existing_from'])->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm'));

                $this->reply("{$patient} ya tiene una cita con {$doctor} el {$existing}, así que no registramos otra para el mismo día. "
                    .'Si necesitás cambiar el horario, usá «Cambiar cita» o escribinos. 🙏');

                return;
            }

            $this->reply("No pudimos confirmar la cita de {$patient} con {$doctor} para el {$when}: ese horario ya no está disponible. "
                .'Escribinos y te ayudamos a elegir otro. 🙏');

            return;
        } catch (Throwable $e) {
            Log::error('[WhatsAppFlow] Error inesperado confirmando la cita del formulario', [
                'doctor_id' => $b['doctor_id'], 'fecha' => $from->toDateTimeString(), 'error' => $e->getMessage(),
            ]);

            $this->reply('Tuvimos un problema al confirmar tu cita. Escribinos y te ayudamos a agendarla. 🙏');

            return;
        }

        // Quién agendó: el recordatorio va a esa persona (decisión del negocio),
        // que además es la única con teléfono si el paciente es un tercero.
        $additional = json_decode((string) DB::table('activities')->where('id', $result['activity_id'])->value('additional'), true) ?: [];
        DB::table('activities')->where('id', $result['activity_id'])->update([
            'additional' => json_encode(array_merge($additional, [
                'booked_by_person_id' => (int) $b['responsible_id'],
                'source'              => 'whatsapp_flow',
            ])),
        ]);

        $catalog->takeSlot((int) $b['doctor_id'], $b['date'], $b['time']);

        $this->reply("✅ Cita confirmada\nPaciente: {$patient}\nEspecialidad: {$b['specialty']}\nDoctor/a: {$doctor}\nFecha: {$when}"
            .($coverage['patient'] ? "\n{$coverage['patient']}" : ''));
    }

    /**
     * Lead "Consulta" abierto que el agente creó para quien escribe: si la cita
     * es "Para mí", se agenda sobre ese lead en vez de crear otro al lado (el
     * agente lo pidió: un paciente quedaba con dos leads). Para un tercero no:
     * ese lead es de quien escribe, no del paciente. Uno derivado a recepción
     * ya no está en Consultas y no se toca.
     */
    protected function consultaLead(int $responsibleId, int $patientId): ?int
    {
        if (! $responsibleId || $responsibleId !== $patientId) {
            return null;
        }

        $id = DB::table('leads')
            ->where('person_id', $patientId)
            ->where('lead_pipeline_stage_id', (int) config('whatsapp.crm.stage_consulta'))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 0))
            ->orderByDesc('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @return array{reason:string, patient:?string} lo que va al motivo (Sofo y
     *                                               SMD) y la línea para el paciente (solo si tiene cobertura vigente)
     */
    protected function checkInsurance(InsuranceService $insurance, int $patientId): array
    {
        try {
            // Si el formulario ya lo verificó (VerifyFlowInsurance), sale de la caché.
            $summary = $insurance->verifyAll($patientId);
        } catch (Throwable $e) {
            Log::warning('[WhatsAppFlow] No se pudo verificar el seguro al agendar', ['patient_id' => $patientId, 'error' => $e->getMessage()]);

            return ['reason' => 'no se pudo verificar', 'patient' => null];
        }

        if (! ($summary['results'] ?? [])) {
            return ['reason' => 'no verificado', 'patient' => null];
        }

        $found = $summary['found'] ?? [];

        if ($found) {
            $vigentes = array_column(array_filter($found, fn ($r) => $r['status'] === 'VIGENTE'), 'seguro_name');

            $estados = implode(', ', array_map(fn ($r) => $r['seguro_name'].' '.mb_strtolower(str_replace('_', ' ', $r['status'])), $found));

            return [
                'reason'  => implode(', ', array_map(fn ($r) => $r['seguro_name'].' '.str_replace('_', ' ', $r['status']), $found)),
                // Mismo criterio que "Revisá tu cita" en el formulario.
                'patient' => $vigentes
                    ? '✅ Seguro válido: '.implode(', ', $vigentes).' (vigente)'
                    : "⚠️ Seguro no válido: {$estados}. Revisá el tema de tu seguro, o al venir a la clínica podés actualizar tus datos.",
            ];
        }

        // Ninguna la encontró, pero alguna no respondió: no se puede afirmar nada.
        $failed = array_column(array_filter($summary['results'] ?? [], fn ($r) => $r['status'] === 'INDETERMINADO'), 'seguro_name');

        return [
            'reason'  => $failed ? 'no encontrado (sin respuesta de '.implode(', ', $failed).')' : 'no encontrado',
            'patient' => null,
        ];
    }

    protected function contactPhone(int $responsibleId, int $conversationId): ?string
    {
        $numbers = json_decode((string) DB::table('persons')->where('id', $responsibleId)->value('contact_numbers'), true);

        return ($numbers[0]['value'] ?? null)
            ?: DB::table('whatsapp_conversations')->where('id', $conversationId)->value('wa_phone');
    }

    protected function reply(string $text): void
    {
        $this->replyAfterAgent((int) $this->booking['conversation_id'], $text);
    }
}
