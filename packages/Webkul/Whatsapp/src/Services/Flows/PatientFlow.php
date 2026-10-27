<?php

namespace Webkul\Whatsapp\Services\Flows;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Admin\Services\BookingOptions;
use Webkul\Admin\Services\InsuranceService;
use Webkul\Admin\Services\PatientIdentityResolver;
use Webkul\Whatsapp\Jobs\VerifyFlowInsurance;
use Webkul\Whatsapp\Models\ConversationProxy;
use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Lógica del Flow "¿Para quién es la cita?" (resources/flows/paciente_tercero.json).
 *
 *   PATIENT_SELECT ──"Para mí" / un paciente a cargo──► CONFIRM
 *         └──(sin cédula cargada)──► PATIENT_CI ──► CONFIRM
 *         └──"Otra persona"──► PATIENT_DATA ──(busca por CI)──► CONFIRM
 *   CONFIRM ──► SPECIALTY ──► DOCTOR ──► SLOT_DATE ──► SLOT_TIME ──► SUMMARY ──► SUCCESS (cita creada)
 *   CONFIRM ──(sin horarios reservables)──► SUMMARY ──► SUCCESS (derivar a recepción)
 *
 * Lo elegido se guarda en FlowSession (servidor), nunca se confía en ids que
 * vuelven del cliente sin revalidarlos.
 *
 * Solo CONFIRM escribe (alta + vínculo): las pantallas anteriores consultan.
 * El cierre devuelve los params que llegan al webhook como `nfm_reply`, y de
 * ahí al agente, que agenda para `patient_id`.
 */
class PatientFlow
{
    /**
     * Qué es el PACIENTE para quien agenda ("El paciente es tu…"). Cubre los dos
     * sentidos: la madre que agenda para su hijo y el hijo que agenda para su
     * padre (decisión: mismo modelo para adultos).
     */
    public const RELATIONS = [
        ['id' => 'hijo', 'title' => 'Hijo/a'],
        ['id' => 'padre_madre', 'title' => 'Padre o madre'],
        ['id' => 'pareja', 'title' => 'Esposo/a o pareja'],
        ['id' => 'hermano', 'title' => 'Hermano/a'],
        ['id' => 'familiar', 'title' => 'Otro familiar'],
        ['id' => 'otro', 'title' => 'Otro'],
    ];

    public function __construct(
        protected PatientIdentityResolver $resolver,
        protected BookingOptions $booking,
        protected InsuranceService $insurance,
    ) {}

    /**
     * Respuesta (sin cifrar) para un pedido ya descifrado.
     */
    public function handle(array $request): array
    {
        $action = $request['action'] ?? null;

        if ($action === 'ping') {
            return ['data' => ['status' => 'active']];
        }

        // Aviso de Meta: una respuesta nuestra no le gustó. Se registra y se acusa.
        if (isset($request['data']['error'])) {
            Log::warning('[WhatsAppFlow] Meta reportó un error en una respuesta del endpoint', [
                'screen' => $request['screen'] ?? null,
                'error'  => $request['data']['error'],
                'detail' => $request['data']['error_message'] ?? null,
            ]);

            return ['data' => ['acknowledged' => true]];
        }

        $conversationId = FlowToken::conversationId($request['flow_token'] ?? null);
        $conversation = $conversationId ? ConversationProxy::modelClass()::find($conversationId) : null;

        if (! $conversation) {
            return $this->select(null, 'No pudimos validar este formulario. Pedí uno nuevo por el chat.');
        }

        $responsible = $conversation->person_id ? (int) $conversation->person_id : null;

        if ($action === 'INIT' && ($fast = $this->fastForward($responsible, (string) ($request['flow_token'] ?? '')))) {
            $fast = $this->asEntry($this->withContext((string) $request['flow_token'], $fast));

            Log::info('[WhatsAppFlow] paso', [
                'conversation' => $conversationId, 'from' => 'INIT', 'to' => $fast['screen'] ?? null, 'v4' => true, 'prefill' => true,
                'error'        => isset($fast['data']['error_message']),
            ]);

            return $fast;
        }

        if ($action === 'INIT' || $action === 'BACK') {
            return $this->select($responsible, null, (string) ($request['flow_token'] ?? ''));
        }

        $data = (array) ($request['data'] ?? []);
        $token = (string) $request['flow_token'];

        $response = $this->withContext($token, match ($request['screen'] ?? null) {
            'PATIENT_SELECT' => $this->afterSelect($responsible, $data, $token),
            'PATIENT_DATA'   => $this->afterData($data, $token, $responsible),
            'PATIENT_CI'     => $this->afterCi($responsible, $data, (string) $request['flow_token']),
            'CONFIRM'        => $this->afterConfirm($responsible, $data, (string) $request['flow_token']),
            'SPECIALTY'      => $this->afterSpecialty((string) $request['flow_token'], $data),
            'DOCTOR'         => $this->afterDoctor((string) $request['flow_token'], $data),
            'NEXT_SLOT'      => $this->afterNextSlot((string) $request['flow_token'], $data),
            // Pantallas de entrada (copias sin transiciones de entrada, ver fastForward).
            'START_SPECIALTY' => $this->afterSpecialty((string) $request['flow_token'], $data),
            'START_DOCTOR'    => $this->afterDoctor((string) $request['flow_token'], $data),
            'START_NEXT_SLOT' => $this->afterNextSlot((string) $request['flow_token'], $data),
            'START_SUMMARY'   => $this->afterSummary((string) $request['flow_token'], $responsible, $data),
            'SLOT_DATE'       => $this->afterDate((string) $request['flow_token'], $data),
            'OTHER_DATE'      => $this->afterOtherDate((string) $request['flow_token'], $data),
            'SLOT_TIME'       => $this->afterTime((string) $request['flow_token'], $data),
            'SUMMARY'         => $this->afterSummary((string) $request['flow_token'], $responsible, $data),
            default           => $this->select($responsible, null, $token),
        });

        // Desde una pantalla de entrada, volver a mostrar "la misma" (con un
        // aviso) es volver a la de entrada: su original no es un destino posible.
        $from = (string) ($request['screen'] ?? '');

        if (str_starts_with($from, 'START_') && ($response['screen'] ?? null) === substr($from, 6)) {
            $response['screen'] = $from;
        }

        // Qué pantalla se respondió a cada paso: sin esto, un rechazo de Meta
        // ("invalid-screen-transition") no se puede reconstruir.
        Log::info('[WhatsAppFlow] paso', [
            'conversation' => $conversationId,
            'from'         => $request['screen'] ?? null,
            'to'           => $response['screen'] ?? null,
            'v4'           => $this->isV4($token),
            'error'        => isset($response['data']['error_message']),
        ]);

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | Versión 4 (paso a paso más claro)
    |--------------------------------------------------------------------------
    | Sin la pantalla "Confirmar" del inicio (la gente creía que ya había
    | confirmado la cita) y con "Paso X de 5" + lo elegido hasta ahí en cada
    | pantalla. Como con PATIENT_CI, el JSON viejo publicado no tiene esto: se
    | enciende con `flows.patient.v4` al publicar la versión 4, o por token
    | para un borrador de prueba.
    */

    protected function isV4(string $token = ''): bool
    {
        if ($token !== '' && ! empty(FlowSession::get($token)['v4'])) {
            return true;
        }

        if (! config('whatsapp.flows.patient.v4')) {
            return false;
        }

        // Un mensaje enviado con el formulario anterior (otro flow_id) se sigue
        // abriendo con ese formulario: responderle pantallas de la v4 hace que
        // Meta las rechace ("invalid-screen-transition") y el paciente ve un error.
        $sentId = $this->sentFlowId($token);

        return $sentId === null || $sentId === (string) config('whatsapp.flows.patient.id');
    }

    /**
     * El flow_id con el que se envió el mensaje de este token; null si no se
     * encuentra (o se envió por nombre).
     */
    protected function sentFlowId(string $token): ?string
    {
        $conversationId = FlowToken::conversationId($token);

        if (! $conversationId) {
            return null;
        }

        return cache()->remember('whatsapp:flow:sent_id:'.md5($token), now()->addDay(), function () use ($conversationId, $token) {
            $payload = MessageProxy::modelClass()::query()
                ->where('conversation_id', $conversationId)
                ->where('type', 'flow')
                ->latest('id')
                ->get(['payload'])
                ->map(fn ($m) => (array) $m->payload)
                ->first(fn ($p) => ($p['flow']['token'] ?? null) === $token);

            return isset($payload['flow']['id']) ? (string) $payload['flow']['id'] : '';
        }) ?: null;
    }

    /**
     * Sigue al paso de la especialidad sin pasar por "Confirmar": hace lo que
     * hacía esa pantalla (guardar la cédula, registrar al familiar).
     */
    protected function proceed(?int $responsible, array $data, string $token): array
    {
        $result = $this->afterConfirm($responsible, $data, $token);

        if (($result['screen'] ?? null) === 'CONFIRM') {
            return $this->select($responsible, $result['data']['error_message'] ?? 'No pudimos confirmar los datos. Escribinos por el chat.', $token);
        }

        return $result;
    }

    /**
     * Versión 4: la línea de arriba de cada pantalla con lo elegido hasta ahí
     * ("Para Juan Pérez · Odontopediatría · Dra. Rojas").
     */
    protected function withContext(string $token, array $response): array
    {
        $screen = $response['screen'] ?? null;

        if (! $this->isV4($token) || ! in_array($screen, ['SPECIALTY', 'DOCTOR', 'NEXT_SLOT', 'SLOT_DATE', 'OTHER_DATE', 'SLOT_TIME'], true)) {
            return $response;
        }

        $session = FlowSession::get($token);
        $parts = [($name = $this->patientName($session)) ? 'Cita para '.explode(' (', $name)[0] : null];

        if ($screen !== 'SPECIALTY') {
            $parts[] = $session['specialty_label'] ?? null;
        }

        if (in_array($screen, ['SLOT_DATE', 'OTHER_DATE', 'SLOT_TIME'], true) && ! empty($session['doctor_id'])) {
            $parts[] = $this->name('doctors', $session['doctor_id']);
        }

        if ($screen === 'SLOT_TIME' && ! empty($session['date'])) {
            $parts[] = $this->dayLabel($session['date']);
        }

        $response['data']['context'] = implode(' · ', array_filter($parts));

        return $response;
    }

    protected function select(?int $responsible, ?string $error = null, string $token = ''): array
    {
        $options = [];

        if ($responsible) {
            $options[] = ['id' => 'self', 'title' => 'Para mí'];

            foreach ($this->resolver->patientsOf($responsible) as $patient) {
                $options[] = ['id' => 'p:'.$patient['id'], 'title' => mb_substr(explode(' (', $patient['name'])[0], 0, 30)];
            }
        }

        $options[] = ['id' => 'other', 'title' => 'Otra persona'];

        $data = ['options' => $options];

        if ($this->prefillOn($token)) {
            $data += $this->initValues(['init_choice' => FlowPrefill::choice(FlowPrefill::forToken($token), $options)]);
        }

        return $this->screen('PATIENT_SELECT', $data, $error);
    }

    protected function afterSelect(?int $responsible, array $data, string $token = ''): array
    {
        $choice = (string) ($data['choice'] ?? '');

        if ($choice === 'other') {
            return $this->patientDataScreen($token);
        }

        if ($choice === 'self' && $responsible) {
            $name = (string) DB::table('persons')->where('id', $responsible)->value('name');

            // Sin cédula no se puede verificar el seguro: se pide antes de seguir.
            if (! $this->hasCiOrSkip($responsible, $token)) {
                return $this->askCi('self', $responsible, $name);
            }

            return $this->isV4($token)
                ? $this->proceed($responsible, ['mode' => 'self', 'patient_id' => (string) $responsible], $token)
                : $this->confirm('self', $responsible, 'La cita es para vos', $name);
        }

        if (str_starts_with($choice, 'p:') && $responsible) {
            $patientId = (int) substr($choice, 2);
            $patient = collect($this->resolver->patientsOf($responsible))->firstWhere('id', $patientId);

            // Solo pacientes ya vinculados a ESTE responsable: el id viene del cliente.
            if ($patient) {
                $name = explode(' (', $patient['name'])[0];

                if (! $this->hasCiOrSkip($patientId, $token)) {
                    return $this->askCi('existing', $patientId, $name);
                }

                return $this->isV4($token)
                    ? $this->proceed($responsible, ['mode' => 'existing', 'patient_id' => (string) $patientId], $token)
                    : $this->confirm('existing', $patientId, 'La cita es para', $name);
            }
        }

        return $this->select($responsible, 'Elegí una opción para continuar.', $token);
    }

    /** "Otra persona": nombre, cédula y parentesco (con valores iniciales si los hay). */
    protected function patientDataScreen(string $token, ?string $error = null): array
    {
        $data = ['relations' => self::RELATIONS];

        if ($this->prefillOn($token)) {
            $prefill = FlowPrefill::forToken($token);
            $data += $this->initValues([
                'init_full_name' => ($prefill['relation'] ?? null) === 'tercero' ? (string) ($prefill['patient_name'] ?? '') : '',
                'init_relation'  => (string) ($prefill['relationship'] ?? ''),
            ]);
        }

        return $this->screen('PATIENT_DATA', $data, $error);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-llenado (lo que el paciente ya dijo en el chat, ver FlowPrefill)
    |--------------------------------------------------------------------------
    | Solo con el JSON que declara los valores iniciales (init_*): el formulario
    | publicado antes no los conoce y Meta rechazaría datos que no declara. Se
    | enciende con `flows.patient.prefill` al publicar esa versión.
    */
    /**
     * Solo los valores iniciales que hay: un init-value vacío ("") hace que el
     * formulario muestre "Select an option" en rojo antes de tocar nada
     * (verificado en la vista previa de Meta, 2026-10-26).
     */
    protected function initValues(array $values): array
    {
        return array_filter($values, fn ($v) => $v !== '');
    }

    /**
     * Sin especialidad pedida pero con doctor: la especialidad de ese doctor
     * que esté entre las opciones ("general" si es una de las generales).
     *
     * @param  array<int, array{id: string, title: string}>  $options
     */
    protected function specialtyOfPrefilledDoctor(array $prefill, array $options): string
    {
        if (empty($prefill['doctor'])) {
            return '';
        }

        $doctors = DB::table('doctors')->where('is_active', 1)->pluck('name', 'id')->all();
        $doctorId = FlowPrefill::doctorId($prefill, $doctors);

        if (! $doctorId) {
            return '';
        }

        $ids = DB::table('doctor_specialty')->where('doctor_id', $doctorId)->pluck('specialty_id')->map(fn ($id) => (int) $id)->all();
        $offered = array_column($options, 'id');

        if (array_intersect($ids, $this->booking->generalSpecialtyIds()) && in_array('general', $offered, true)) {
            return 'general';
        }

        foreach ($ids as $id) {
            if (in_array((string) $id, $offered, true)) {
                return (string) $id;
            }
        }

        return '';
    }

    protected function prefillOn(string $token): bool
    {
        return $token !== '' && config('whatsapp.flows.patient.prefill') && $this->isV4($token);
    }

    /**
     * Doctor, día y hora (R7–R9): sus init_* los declara una versión posterior
     * del JSON. Con el formulario v5 publicado mandarlos haría que Meta rechace
     * la pantalla, así que van aparte, con `flows.patient.prefill_schedule`.
     */
    protected function prefillScheduleOn(string $token): bool
    {
        return $this->prefillOn($token) && config('whatsapp.flows.patient.prefill_schedule');
    }

    /** Valores iniciales de día/hora, salvo que el paciente rechazó el turno sugerido. */
    protected function scheduleInitOn(string $token): bool
    {
        return $this->prefillScheduleOn($token) && empty(FlowSession::get($token)['declined_suggestion']);
    }

    protected function afterData(array $data, string $token = '', ?int $responsible = null): array
    {
        $name = trim((string) ($data['full_name'] ?? ''));
        $ci = (string) ($data['ci'] ?? '');
        $relation = (string) ($data['relation'] ?? '');
        $birthdate = (string) ($data['birthdate'] ?? '');

        $found = $this->resolver->lookup($ci, $name);

        if ($found['status'] === 'invalid') {
            return $this->patientDataScreen($token, 'Revisá el nombre completo y la cédula.');
        }

        // No se dice a nombre de quién está la cédula: sería revelar datos de otra persona.
        if ($found['status'] === 'mismatch') {
            return $this->patientDataScreen($token, 'Esa cédula figura con otro nombre. Revisala o escribinos por el chat.');
        }

        $known = in_array($found['status'], ['crm', 'smd'], true);
        $this->rememberLookup($token, $found);

        if ($this->isV4($token)) {
            return $this->proceed($responsible, [
                'mode' => 'other', 'full_name' => $name, 'ci' => $found['ci'], 'birthdate' => $birthdate, 'relation' => $relation,
            ], $token);
        }

        return $this->screen('CONFIRM', [
            'mode'       => 'other',
            'patient_id' => '',
            'heading'    => $known ? 'Encontramos al paciente' : 'Vamos a registrar al paciente',
            'detail'     => ($found['display_name'] ?? $name).' · CI '.$found['ci'],
            'full_name'  => $name,
            'ci'         => $found['ci'],
            'birthdate'  => $birthdate,
            'relation'   => $relation,
        ]);
    }

    /**
     * La pantalla PATIENT_CI solo existe en el Flow publicado desde que se
     * activa `flows.patient.ask_ci`: con el JSON viejo en Meta, mandarla rompe
     * el formulario. Se enciende después de publicar la versión nueva.
     *
     * Un formulario de prueba (borrador de la versión nueva) la activa solo
     * para su token: `ask_ci` en su FlowSession.
     */
    protected function hasCiOrSkip(int $personId, string $token = ''): bool
    {
        return ! $this->isNewVersion($token) || $this->resolver->ciFor($personId);
    }

    /**
     * ¿El formulario abierto es la versión con cédula y seguro (PATIENT_CI,
     * seguro en el resumen)? Con la versión vieja publicada, mandar esas
     * pantallas (o campos que su JSON no declara) lo rompe.
     */
    protected function isNewVersion(string $token = ''): bool
    {
        return config('whatsapp.flows.patient.ask_ci') || ($token !== '' && ! empty(FlowSession::get($token)['ask_ci']));
    }

    protected function askCi(string $mode, int $patientId, string $name, ?string $error = null): array
    {
        return $this->screen('PATIENT_CI', [
            'mode'       => $mode,
            'patient_id' => (string) $patientId,
            'heading'    => $mode === 'self' ? 'Necesitamos tu cédula' : 'Necesitamos la cédula de '.$name,
            'full_name'  => $name,
        ], $error);
    }

    /**
     * Cédula de "Para mí" o de un paciente a cargo que no la tenía: se valida
     * (sin escribir) y se confirma; se guarda recién en CONFIRM.
     */
    protected function afterCi(?int $responsible, array $data, string $token = ''): array
    {
        $mode = (string) ($data['mode'] ?? '');
        $patientId = $this->allowedPatient($responsible, $mode, (int) ($data['patient_id'] ?? 0));
        $fullName = trim((string) ($data['full_name'] ?? ''));

        if (! $patientId) {
            return $this->select($responsible, 'Elegí una opción para continuar.', $token);
        }

        $result = $this->resolver->claimCi($patientId, $responsible, (string) ($data['ci'] ?? ''), $fullName, false);

        if ($result['status'] === 'ok') {
            $this->rememberLookup($token, $result['found']);
        }

        if ($result['status'] !== 'ok') {
            return $this->askCi($mode, $patientId, $fullName, $result['status'] === 'invalid'
                ? 'Revisá el nombre completo y la cédula.'
                : 'Esa cédula figura con otro nombre. Revisala o escribinos por el chat.');
        }

        if ($this->isV4($token)) {
            return $this->proceed($responsible, [
                'mode' => $mode, 'patient_id' => (string) $patientId, 'full_name' => $fullName, 'ci' => $result['ci'],
            ], $token);
        }

        return $this->screen('CONFIRM', [
            'mode'       => $mode,
            'patient_id' => (string) $patientId,
            'heading'    => $mode === 'self' ? 'La cita es para vos' : 'La cita es para',
            'detail'     => $result['display_name'].' · CI '.$result['ci'],
            'full_name'  => $fullName,
            'ci'         => $result['ci'],
            'birthdate'  => '',
            'relation'   => '',
        ]);
    }

    /** El paciente de "Para mí" o uno ya vinculado: el id viene del cliente. */
    protected function allowedPatient(?int $responsible, string $mode, int $patientId): ?int
    {
        if (! $responsible) {
            return null;
        }

        if ($mode === 'self') {
            return $responsible;
        }

        return $mode === 'existing' && collect($this->resolver->patientsOf($responsible))->contains('id', $patientId)
            ? $patientId
            : null;
    }

    protected function afterConfirm(?int $responsible, array $data, string $token): array
    {
        if (! $responsible) {
            return $this->confirmError($data, 'No pudimos identificarte. Escribinos por el chat.');
        }

        $mode = (string) ($data['mode'] ?? '');

        if ($mode === 'self' || $mode === 'existing') {
            $patientId = $this->allowedPatient($responsible, $mode, (int) ($data['patient_id'] ?? 0));

            if (! $patientId) {
                return $this->confirmError($data, 'No pudimos confirmar al paciente. Escribinos por el chat.');
            }

            // Viene de PATIENT_CI: ahora sí se guarda la cédula.
            if (($data['ci'] ?? '') !== '') {
                $claimed = $this->resolver->claimCi($patientId, $responsible, (string) $data['ci'], (string) ($data['full_name'] ?? ''), true, $this->recalledLookup($token, (string) $data['ci']));

                if ($claimed['status'] !== 'ok') {
                    return $this->confirmError($data, 'No pudimos confirmar la cédula. Escribinos por el chat.');
                }

                $patientId = $claimed['patient_id'];
            }

            return $this->toBooking($token, $responsible, $patientId, $patientId === $responsible ? 'self' : 'found');
        }

        $input = [
            'ci'        => (string) ($data['ci'] ?? ''),
            'name'      => (string) ($data['full_name'] ?? ''),
            'birthdate' => ($data['birthdate'] ?? '') ?: null,
            'relation'  => ($data['relation'] ?? '') ?: null,
        ];
        $found = $this->recalledLookup($token, $input['ci']);

        // Paciente que hay que dar de alta: lo hace el job que crea la cita. El
        // alta dispara el registro en SMD y no entra en el tiempo que Meta
        // espera la respuesta ("Error en la respuesta del punto de conexión").
        if ($found && in_array($found['status'], ['smd', 'new'], true)) {
            FlowSession::merge($token, ['pending_patient' => ['input' => $input, 'found' => $found]]);

            return $this->toBooking($token, $responsible, 0, 'created', $input['relation']);
        }

        $result = $this->resolver->commit($responsible, $input, 'whatsapp_flow', $found);

        if (! isset($result['patient_id'])) {
            return $this->confirmError($data, 'No pudimos confirmar los datos. Escribinos por el chat.');
        }

        return $this->toBooking($token, $responsible, $result['patient_id'], $result['status'], $data['relation'] ?? null);
    }

    /*
    |--------------------------------------------------------------------------
    | Agenda: especialidad → doctor → día → hora → resumen
    |--------------------------------------------------------------------------
    */

    protected function toBooking(string $token, int $responsible, int $patientId, string $status, ?string $relation = null): array
    {
        FlowSession::merge($token, [
            'responsible_id' => $responsible,
            'patient_id'     => $patientId,
            'patient_status' => $status,
            'relation'       => $relation,
        ]);

        // Versión nueva: el seguro se verifica en segundo plano contra todas
        // las aseguradoras mientras elige especialidad, doctor y horario, y el
        // resultado se muestra en "Revisá tu cita".
        if ($this->isNewVersion($token)) {
            FlowSession::merge($token, ['check_insurance' => true]);
            $this->startInsuranceCheck(FlowSession::get($token));
        }

        return $this->toSpecialties($token);
    }

    /*
    | Seguro: se verifica con el carnet contra todas las aseguradoras
    */

    /**
     * El carnet con el que se verifica: el corregido en el resumen, el de la
     * ficha, o el del paciente nuevo (todavía sin ficha).
     */
    protected function flowCi(array $session): ?string
    {
        return ($session['ci_override'] ?? null)
            ?: (! empty($session['patient_id']) ? $this->resolver->ciFor((int) $session['patient_id']) : null)
            ?: ($session['pending_patient']['input']['ci'] ?? null);
    }

    protected function patientName(array $session): string
    {
        return ! empty($session['patient_id'])
            ? $this->name('persons', $session['patient_id'])
            : (string) ($session['pending_patient']['input']['name'] ?? '');
    }

    protected function startInsuranceCheck(array $session): void
    {
        if ($ci = $this->flowCi($session)) {
            VerifyFlowInsurance::dispatch($ci);
        }
    }

    /**
     * El bloque del seguro en "Revisá tu cita" y si hay que mostrarle el
     * carnet para que lo corrija (cuando el problema puede ser el carnet).
     *
     * @return array{0: ?string, 1: bool}
     */
    protected function insuranceBlock(array $session, float $until): array
    {
        if (empty($session['check_insurance'])) {
            return [null, false];
        }

        $ci = $this->flowCi($session);
        $fix = 'Revisá tu carnet: si está mal, corregilo abajo y tocá Confirmar para verificarlo de nuevo. También podés actualizar tus datos al venir a la clínica.';

        if (! $ci) {
            return ["Seguro: sin carnet no lo pudimos verificar.\nAl venir a la clínica podés actualizar tus datos.", false];
        }

        $summary = $this->waitForInsurance($ci, $until);

        if (! $summary) {
            return ["⏳ Seguro: todavía estamos verificando con la aseguradora.\nTe confirmamos por el chat.", false];
        }

        $vigentes = array_filter($summary['found'], fn ($r) => $r['status'] === 'VIGENTE');

        if ($vigentes) {
            return ['✅ Seguro válido: '.implode(', ', array_column($vigentes, 'seguro_name')).' (vigente)', false];
        }

        if ($summary['found']) {
            $estados = implode(', ', array_map(fn ($r) => $r['seguro_name'].' '.mb_strtolower(str_replace('_', ' ', $r['status'])), $summary['found']));

            return ["⚠️ Seguro no válido: {$estados}.\n{$fix}", true];
        }

        $failed = array_filter($summary['results'], fn ($r) => $r['status'] === 'INDETERMINADO');

        if ($failed && count($failed) === count($summary['results'])) {
            return ["⚠️ No pudimos verificar tu seguro en este momento.\nAl venir a la clínica podés actualizar tus datos.", false];
        }

        return ["ℹ️ No encontramos seguro ni membresía con el carnet {$ci}.\nSi tenés uno, {$fix}", true];
    }

    /**
     * El resultado de la verificación que arrancó en segundo plano. Si todavía
     * no está, se espera hasta `$until`: Meta da ~10 s a cada pantalla.
     */
    protected function waitForInsurance(string $ci, float $until): ?array
    {
        do {
            $summary = $this->insurance->peekCi($ci);

            if ($summary || microtime(true) >= $until) {
                return $summary;
            }

            usleep(400_000);
        } while (true);
    }

    protected function insuranceDeadline(): float
    {
        return microtime(true) + max(0, (float) config('whatsapp.flows.insurance_wait_seconds', 5));
    }

    /**
     * "Revisá tu cita": los datos de la cita y el estado del seguro, con el
     * carnet editable si hay un problema con el seguro.
     */
    protected function summaryScreen(string $token, ?string $error = null, ?float $until = null): array
    {
        $session = FlowSession::get($token);
        [$insurance, $showCi] = $this->insuranceBlock($session, $until ?? $this->insuranceDeadline());

        return $this->screen('SUMMARY', [
            'heading' => 'Revisá tu cita',
            'detail'  => implode("\n", array_filter([
                'Paciente: '.$this->patientName($session),
                'Especialidad: '.$this->name('specialties', $session['specialty_id'] ?? 0),
                'Doctor/a: '.$this->name('doctors', $session['doctor_id'] ?? 0),
                'Fecha: '.$this->label(($session['date'] ?? '').' '.($session['time'] ?? '')),
                // Línea en blanco: el seguro va como bloque aparte.
                $insurance ? "\n".$insurance : null,
                // Versión 4: que quede claro que todavía falta confirmar.
                $this->isV4($token) ? "\nTocá «Confirmar cita» para agendarla." : null,
            ])),
        ] + $this->ciFields($token, $showCi ? (string) $this->flowCi($session) : null), $error);
    }

    /** SUMMARY sin seguro (derivar a recepción, faltan datos…). */
    protected function plainSummary(string $token, string $heading, string $detail, ?string $error = null): array
    {
        return $this->screen('SUMMARY', ['heading' => $heading, 'detail' => $detail] + $this->ciFields($token, null), $error);
    }

    /**
     * El carnet editable del resumen: solo existe en la versión nueva del
     * Flow (la vieja no declara esos campos).
     */
    protected function ciFields(string $token, ?string $ci): array
    {
        return $this->isNewVersion($token) ? ['ci' => (string) $ci, 'show_ci' => $ci !== null] : [];
    }

    /**
     * Corrigió el carnet en el resumen: se valida contra el nombre del
     * paciente y se vuelve a verificar el seguro. La cita no se agenda hasta
     * que confirme sin cambios.
     */
    protected function changeCi(string $token, array $session, string $newCi): array
    {
        $until = $this->insuranceDeadline() + 2;
        $ci = $this->resolver->normalizeCi($newCi);

        // Primero la verificación (en segundo plano), así corre mientras se valida.
        VerifyFlowInsurance::dispatch($ci);

        $found = $this->resolver->lookup($ci, $this->patientName($session));
        $otherFile = $found['status'] === 'crm' && ! empty($session['patient_id']) && $found['person_id'] !== (int) $session['patient_id'];

        if (in_array($found['status'], ['invalid', 'mismatch'], true) || $otherFile) {
            return $this->summaryScreen($token, $found['status'] === 'invalid'
                ? 'Ese carnet no es válido. Revisalo.'
                : 'Ese carnet figura con otro nombre. Revisalo o actualizá tus datos en la clínica.');
        }

        if (! empty($session['pending_patient'])) {
            // Paciente nuevo: se da de alta con el carnet corregido.
            FlowSession::merge($token, ['pending_patient' => [
                'input' => array_merge($session['pending_patient']['input'], ['ci' => $ci]),
                'found' => $found,
            ]]);
        } else {
            // Paciente con ficha: el job que agenda le actualiza el carnet.
            FlowSession::merge($token, ['ci_override' => $ci]);
        }

        return $this->summaryScreen($token, 'Actualizamos tu carnet. Revisá el seguro y tocá Confirmar.', $until);
    }

    protected function toSpecialties(string $token): array
    {
        $specialties = $this->booking->specialties();

        // Nada reservable por el formulario: se cierra derivando a recepción.
        if (! $specialties) {
            FlowSession::merge($token, ['handoff' => true]);

            return $this->plainSummary($token, 'No encontramos horarios disponibles', 'Tocá Confirmar y una persona de recepción te ayuda a agendar por el chat.');
        }

        return $this->specialtyScreen($specialties, null, $token);
    }

    protected function specialtyScreen(array $specialties, ?string $error = null, string $token = ''): array
    {
        // Las especialidades generales ("General", "Odontología General"…) van
        // como UNA opción, que además sirve a quien no sabe qué necesita.
        $general = $this->booking->generalSpecialtyIds();
        $options = [];

        if ($general) {
            $options[] = ['id' => 'general', 'title' => 'General / No sé qué necesito'];
        }

        foreach ($specialties as $s) {
            if (! in_array($s['id'], $general, true)) {
                $options[] = ['id' => (string) $s['id'], 'title' => mb_substr(trim($s['name']), 0, 30)];
            }
        }

        $data = ['specialties' => $options];

        if ($this->prefillOn($token)) {
            $prefill = FlowPrefill::forToken($token);
            $init = FlowPrefill::specialty($prefill, $options) ?: $this->specialtyOfPrefilledDoctor($prefill, $options);
            $data += $this->initValues(['init_specialty' => $init]);
        }

        return $this->screen('SPECIALTY', $data, $error);
    }

    protected function afterSpecialty(string $token, array $data): array
    {
        $specialties = $this->booking->specialties();
        $general = $this->booking->generalSpecialtyIds();
        $choice = (string) ($data['specialty'] ?? '');

        // "General" (o una especialidad general elegida desde una versión vieja
        // del formulario) junta a los doctores de todas las generales.
        $ids = $choice === 'general' || in_array((int) $choice, $general, true)
            ? $general
            : array_values(array_filter([(int) $choice], fn ($id) => collect($specialties)->contains('id', $id)));

        if (! $ids || ! $this->hasPatient($token)) {
            return $this->specialtyScreen($specialties, 'Elegí una especialidad.', $token);
        }

        // Recién acá se consulta SMD: 1 consulta por especialidad elegida (2 si
        // "General" junta dos), los próximos días (planning/15-consumo-api-smd).
        $from = now(config('app.timezone'))->toDateString();
        $until = $this->deadline();
        $slots = [];
        $doctors = [];
        $waiting = false;
        $failed = 0;

        foreach ($ids as $id) {
            $avail = $this->booking->availability($id, $from, $this->booking->days(), $until);

            if ($avail === null) {
                $waiting = true;

                continue;
            }

            if ($avail['failed']) {
                $failed++;

                continue;
            }

            foreach ($this->booking->doctorsOf($id) as $doctor) {
                if (! empty($avail['slots'][$doctor['id']]) && ! isset($doctors[$doctor['id']])) {
                    $doctors[$doctor['id']] = $doctor + ['specialty_id' => $id];
                    $slots[$doctor['id']] = $avail['slots'][$doctor['id']];
                }
            }
        }

        if ($waiting) {
            return $this->specialtyScreen($specialties, 'Estamos buscando los horarios. Volvé a tocar el botón de abajo en unos segundos.', $token);
        }

        if ($failed === count($ids)) {
            return $this->specialtyScreen($specialties, 'No pudimos traer los horarios ahora. Probá en unos minutos o escribinos por el chat.', $token);
        }

        // Solo se ofrecen doctores con horarios: uno sin horarios confunde, el
        // aviso en letra chica no se lee.
        if (! $doctors) {
            return $this->specialtyScreen($specialties, 'No hay horarios libres en los próximos '.$this->booking->days().' días para esa especialidad. Probá con otra o escribinos por el chat.', $token);
        }

        $label = $choice === 'general' || count($ids) > 1 ? 'General' : $this->name('specialties', $ids[0]);

        // Lo consultado queda en la sesión: los pasos siguientes no vuelven a
        // pedir nada a SMD aunque la respuesta guardada venza.
        FlowSession::merge($token, [
            'specialty_id'    => $ids[0],
            'specialty_label' => $label,
            'week_slots'      => $slots,
            'week_doctors'    => array_map(fn ($d) => $d['specialty_id'], $doctors),
            'doctor_id'       => null, 'doctor_slots' => [], 'date' => null, 'time' => null,
        ]);

        $rows = array_map(function ($d) use ($slots) {
            $first = array_key_first($slots[$d['id']]);

            return $d + ['first' => $first.' '.$slots[$d['id']][$first][0]];
        }, array_values($doctors));

        // El horario más próximo primero.
        usort($rows, fn ($a, $b) => strcmp($a['first'], $b['first']));

        $doctorOptions = [
            'doctors' => array_map(fn ($d) => [
                'id'          => (string) $d['id'],
                'title'       => mb_substr(trim($d['name']), 0, 30),
                // Solo el día: con la hora parecía que la cita ya estaba
                // elegida y después se volvía a preguntar día y hora.
                'description' => 'Tiene horarios desde el '.$this->dayLabel(explode(' ', $d['first'])[0]),
            ], $rows),
        ];

        if ($this->prefillScheduleOn($token)) {
            $doctorId = FlowPrefill::doctorId(FlowPrefill::forToken($token), array_column($rows, 'name', 'id'));
            $doctorOptions += $this->initValues(['init_doctor' => $doctorId ? (string) $doctorId : '']);
        }

        return $this->screen('DOCTOR', $doctorOptions);
    }

    protected function afterDoctor(string $token, array $data): array
    {
        $session = FlowSession::get($token);
        $doctorId = (int) ($data['doctor'] ?? 0);
        $offered = (array) ($session['week_doctors'] ?? []);

        // Solo uno de los doctores ofrecidos (el id viene del teléfono).
        if (! isset($offered[$doctorId])) {
            return $this->afterSpecialty($token, ['specialty' => ($session['specialty_label'] ?? '') === 'General' ? 'general' : (string) ($session['specialty_id'] ?? '')]);
        }

        $slots = $session['week_slots'][$doctorId] ?? [];

        // La especialidad real de ese doctor (en "General" puede ser cualquiera
        // de las generales): con esa se consulta "otra fecha" y se agenda.
        FlowSession::merge($token, [
            'specialty_id' => (int) $offered[$doctorId],
            'doctor_id'    => $doctorId, 'doctor_slots' => $slots, 'date' => null, 'time' => null,
            'suggested'    => null, 'declined_suggestion' => false,
        ]);

        return $this->nextSlotScreen($token, $doctorId, $slots) ?? $this->dateScreen($slots, null, $token);
    }

    /*
    |--------------------------------------------------------------------------
    | Turno sugerido (después de elegir el doctor)
    |--------------------------------------------------------------------------
    | El turno libre más próximo del doctor (o el pedido en el chat, si está
    | libre) como primera opción ya marcada: "Continuar" va directo al resumen.
    | "Quiero otra fecha y hora" sigue con la elección normal de día y hora.
    | Solo con el JSON que tiene la pantalla NEXT_SLOT (v6).
    */
    protected function nextSlotScreen(string $token, int $doctorId, array $slots): ?array
    {
        if (! $this->prefillScheduleOn($token) || ! $slots) {
            return null;
        }

        $prefill = FlowPrefill::forToken($token);
        $date = FlowPrefill::date($prefill, array_keys($slots), $this->maxDate());

        // Pedido más allá de la lista de días: va a "Otra fecha" con ese día puesto.
        if ($date === 'other') {
            return null;
        }

        $date = $date !== '' ? $date : (string) array_key_first($slots);
        $times = array_values($slots[$date] ?? []);
        $time = FlowPrefill::time($prefill, $times) ?: ($times[0] ?? null);

        if (! $time) {
            return null;
        }

        FlowSession::merge($token, ['suggested' => ['date' => $date, 'time' => $time]]);

        $asked = ($prefill['date'] ?? null) === $date && ($prefill['time'] ?? null) === $time;
        $doctor = $this->name('doctors', $doctorId);

        return $this->screen('NEXT_SLOT', [
            'heading' => ($asked ? 'El turno que pediste con ' : 'El turno más próximo con ').$doctor,
            'options' => [
                ['id' => 'accept', 'title' => mb_substr($this->dayLabel($date).' a las '.$time, 0, 30), 'description' => 'Si te sirve, tocá Continuar'],
                ['id' => 'other', 'title' => 'Quiero otra fecha y hora'],
            ],
            'init_choice' => 'accept',
        ]);
    }

    /**
     * Formulario pre-llenado con paciente conocido: hace por el paciente los
     * pasos ya respondidos en el chat (los mismos métodos, con sus validaciones:
     * cédula, seguro, agenda real) y abre en el primero que necesita una
     * decisión. Con todo resuelto y el turno pedido libre, abre en el resumen.
     * Un tercero nuevo (hay que pedirle nombre y cédula) empieza como siempre.
     */
    protected function fastForward(?int $responsible, string $token): ?array
    {
        // Meta solo deja abrir en pantallas SIN transiciones de entrada: las
        // START_* del JSON (v7). Con un JSON anterior, abrir en SUMMARY da
        // "Unexpected screen", por eso va con su propia opción.
        if (! $responsible || ! $this->prefillScheduleOn($token) || ! config('whatsapp.flows.patient.fast_forward')) {
            return null;
        }

        $prefill = FlowPrefill::forToken($token);
        $choice = (string) ($this->select($responsible, null, $token)['data']['init_choice'] ?? '');

        if ($choice === '' || $choice === 'other') {
            return null;
        }

        // 1. Paciente ("Para mí" o el familiar a cargo). Si falta la cédula se
        // empieza como siempre: PATIENT_CI no puede ser la primera pantalla.
        $specialtyScreen = $this->afterSelect($responsible, ['choice' => $choice], $token);

        if (($specialtyScreen['screen'] ?? null) !== 'SPECIALTY') {
            return null;
        }

        // 2. Especialidad (la pedida o la del doctor pedido).
        $specialty = (string) ($specialtyScreen['data']['init_specialty'] ?? '');

        if ($specialty === '') {
            return $specialtyScreen;
        }

        $doctorScreen = $this->afterSpecialty($token, ['specialty' => $specialty]);

        if (($doctorScreen['screen'] ?? null) !== 'DOCTOR') {
            return $doctorScreen; // la especialidad, con el aviso (sin horarios, SMD lento…)
        }

        // 3. Doctor.
        $doctor = (string) ($doctorScreen['data']['init_doctor'] ?? '');

        if ($doctor === '') {
            return $doctorScreen;
        }

        $nextScreen = $this->afterDoctor($token, ['doctor' => $doctor]);

        // Sin turno sugerido (p.ej. día pedido fuera de la semana: "Otra fecha"),
        // se abre en el doctor ya marcado: SLOT_DATE no puede ser la primera.
        if (($nextScreen['screen'] ?? null) !== 'NEXT_SLOT') {
            return $doctorScreen;
        }

        // 4. El turno pedido está libre: directo a "Revisá tu cita". Si no
        // (o no vino hora), "Turno sugerido" con el más cercano.
        $suggested = (array) (FlowSession::get($token)['suggested'] ?? []);

        if (isset($prefill['date'], $prefill['time'])
            && ($suggested['date'] ?? null) === $prefill['date'] && ($suggested['time'] ?? null) === $prefill['time']) {
            FlowSession::merge($token, ['date' => $prefill['date'], 'time' => $prefill['time']]);

            // Meta corta a ~10 s y ya se consultó la agenda: el seguro se espera
            // poco ("verificando" si no llegó; se confirma igual al agendar).
            return $this->summaryScreen($token, null, microtime(true) + 1.5);
        }

        return $nextScreen;
    }

    /** La pantalla de entrada (START_*) de una respuesta del atajo. */
    protected function asEntry(array $response): array
    {
        if (in_array($response['screen'] ?? null, ['SPECIALTY', 'DOCTOR', 'NEXT_SLOT', 'SUMMARY'], true)) {
            $response['screen'] = 'START_'.$response['screen'];
        }

        return $response;
    }

    protected function afterNextSlot(string $token, array $data): array
    {
        $session = FlowSession::get($token);
        $slots = $this->sessionSlots($session);
        $suggested = (array) ($session['suggested'] ?? []);
        $date = (string) ($suggested['date'] ?? '');
        $time = (string) ($suggested['time'] ?? '');

        // Aceptado y todavía libre en lo consultado: directo al resumen.
        if (($data['slot_choice'] ?? null) === 'accept' && in_array($time, $slots[$date] ?? [], true)) {
            FlowSession::merge($token, ['date' => $date, 'time' => $time]);

            return $this->summaryScreen($token);
        }

        // "Otra fecha y hora": elección normal, sin volver a marcar la sugerencia.
        FlowSession::merge($token, ['declined_suggestion' => true, 'date' => null, 'time' => null]);

        return $this->dateScreen($slots, null, $token);
    }

    protected function dateScreen(array $slots, ?string $error = null, string $token = ''): array
    {
        $dates = array_map(fn ($date) => ['id' => $date, 'title' => $this->dayLabel($date)], array_keys($slots));
        $dates[] = ['id' => 'other', 'title' => 'Otra fecha'];
        $data = ['dates' => $dates];

        if ($this->scheduleInitOn($token)) {
            $data += $this->initValues(['init_date' => FlowPrefill::date(FlowPrefill::forToken($token), array_keys($slots), $this->maxDate())]);
        }

        return $this->screen('SLOT_DATE', $data, $error);
    }

    protected function maxDate(): string
    {
        return now(config('app.timezone'))->addDays($this->booking->maxDays() - 1)->toDateString();
    }

    protected function afterDate(string $token, array $data): array
    {
        $session = FlowSession::get($token);
        $slots = $this->sessionSlots($session);
        $date = (string) ($data['date'] ?? '');

        if ($date === 'other') {
            return $this->otherDateScreen($session, null, $token);
        }

        if (! isset($slots[$date])) {
            return $this->dateScreen($slots, 'Elegí un día de la lista.', $token);
        }

        FlowSession::merge($token, ['date' => $date, 'time' => null]);

        return $this->timeScreen($date, $slots[$date], null, $token);
    }

    /**
     * "Otra fecha": el paciente elige el día y se consulta SMD solo por ese día.
     */
    protected function otherDateScreen(array $session, ?string $error = null, string $token = ''): array
    {
        $tz = config('app.timezone');
        $data = [
            'heading'  => '¿Qué día te queda bien con '.$this->name('doctors', $session['doctor_id'] ?? 0).'?',
            'min_date' => now($tz)->toDateString(),
            'max_date' => $this->maxDate(),
        ];

        // El día pedido en el chat, si se puede pedir (entre hoy y el máximo).
        if ($this->scheduleInitOn($token)) {
            $wanted = (string) (FlowPrefill::forToken($token)['date'] ?? '');
            $data += $this->initValues(['init_other_date' => $wanted >= $data['min_date'] && $wanted <= $data['max_date'] ? $wanted : '']);
        }

        return $this->screen('OTHER_DATE', $data, $error);
    }

    protected function afterOtherDate(string $token, array $data): array
    {
        $session = FlowSession::get($token);
        $tz = config('app.timezone');
        $doctorId = (int) ($session['doctor_id'] ?? 0);
        $specialtyId = (int) ($session['specialty_id'] ?? 0);

        try {
            $date = Carbon::parse((string) ($data['date'] ?? ''), $tz)->toDateString();
        } catch (\Throwable $e) {
            $date = null;
        }

        $today = now($tz)->startOfDay();

        if (! $doctorId || ! $specialtyId || ! $date
            || Carbon::parse($date, $tz)->lt($today) || Carbon::parse($date, $tz)->gt($today->copy()->addDays($this->booking->maxDays() - 1))) {
            return $this->otherDateScreen($session, 'Elegí un día entre hoy y los próximos '.$this->booking->maxDays().' días.', $token);
        }

        // Ya consultado (está en los horarios de la semana): no se pide de nuevo.
        $times = $session['doctor_slots'][$date] ?? null;

        if ($times === null) {
            $avail = $this->booking->availability($specialtyId, $date, 1, $this->deadline());

            if ($avail === null) {
                return $this->otherDateScreen($session, 'Estamos buscando los horarios de ese día. Volvé a tocar el botón de abajo en unos segundos.', $token);
            }

            if ($avail['failed']) {
                return $this->otherDateScreen($session, 'No pudimos traer los horarios ahora. Probá en unos minutos o escribinos por el chat.', $token);
            }

            $times = $avail['slots'][$doctorId][$date] ?? [];
        }

        if (! $times) {
            return $this->otherDateScreen($session, 'No hay horarios libres el '.$this->dayLabel($date).' con '.$this->name('doctors', $doctorId).'. Elegí otro día.', $token);
        }

        $slots = $session['doctor_slots'] ?? [];
        $slots[$date] = $times;
        ksort($slots);

        FlowSession::merge($token, ['doctor_slots' => $slots, 'date' => $date, 'time' => null]);

        return $this->timeScreen($date, $times, null, $token);
    }

    /** Hasta cuándo una pantalla espera la respuesta de SMD (Meta corta a ~10 s). */
    protected function deadline(): float
    {
        return microtime(true) + max(0, (float) config('whatsapp.flows.booking.wait_seconds', 7));
    }

    protected function timeScreen(string $date, array $times, ?string $error = null, string $token = ''): array
    {
        $data = [
            'heading' => 'Horarios del '.$this->dayLabel($date),
            'times'   => array_map(fn ($t) => ['id' => $t, 'title' => $t], $times),
        ];

        if ($this->scheduleInitOn($token)) {
            $data += $this->initValues(['init_time' => FlowPrefill::time(FlowPrefill::forToken($token), array_values($times))]);
        }

        return $this->screen('SLOT_TIME', $data, $error);
    }

    protected function afterTime(string $token, array $data): array
    {
        $session = FlowSession::get($token);
        $slots = $this->sessionSlots($session);
        $date = (string) ($session['date'] ?? '');
        $time = (string) ($data['time'] ?? '');

        if (! in_array($time, $slots[$date] ?? [], true)) {
            return $this->timeScreen($date, $slots[$date] ?? [], 'Elegí un horario de la lista.', $token);
        }

        FlowSession::merge($token, ['time' => $time]);

        return $this->summaryScreen($token);
    }

    protected function afterSummary(string $token, ?int $responsible, array $data = []): array
    {
        $session = FlowSession::get($token);

        // Corrigió el carnet: se vuelve a verificar y se muestra el resumen otra vez.
        $newCi = trim((string) ($data['ci'] ?? ''));

        if ($newCi !== '' && ! empty($session['check_insurance'])
            && $this->resolver->normalizeCi($newCi) !== $this->resolver->normalizeCi((string) $this->flowCi($session))) {
            return $this->changeCi($token, $session, $newCi);
        }

        if (! empty($session['handoff'])) {
            FlowSession::forget($token);

            return $this->success($token, [
                'status'       => 'handoff',
                'patient_id'   => ($session['patient_id'] ?? 0) ?: null,
                'patient_name' => $session['pending_patient']['input']['name'] ?? null,
                'patient_ci'   => $session['pending_patient']['input']['ci'] ?? null,
            ]);
        }

        foreach (['specialty_id', 'doctor_id', 'date', 'time'] as $required) {
            if (empty($session[$required]) || ! $this->hasPatient($token)) {
                return $this->plainSummary($token, 'Faltan datos', 'Volvé atrás y completá la cita.', 'Faltan datos de la cita.');
            }
        }

        // Último control barato antes de aceptar: que el horario siga en el
        // catálogo (otro pudo tomarlo mientras este usuario completaba).
        if (! in_array($session['time'], $this->sessionSlots($session)[$session['date']] ?? [], true)
            || $this->booking->wasTaken((int) $session['doctor_id'], $session['date'], $session['time'])) {
            return $this->plainSummary($token, 'Ese horario ya no está disponible', 'Volvé atrás y elegí otro horario.', 'Ese horario se acaba de ocupar. Elegí otro.');
        }

        // La cita se crea en segundo plano (BookFlowAppointment): crearla en
        // vivo tarda más de lo que Meta espera y el usuario veía un error aunque
        // la cita quedaba creada. El horario se saca del catálogo ya mismo.
        $this->booking->takeSlot((int) $session['doctor_id'], $session['date'], $session['time']);

        $conversationId = FlowToken::conversationId($token);

        \Webkul\Whatsapp\Jobs\BookFlowAppointment::dispatch([
            'conversation_id' => (int) $conversationId,
            'responsible_id'  => (int) ($session['responsible_id'] ?? $responsible),
            'patient_id'      => (int) ($session['patient_id'] ?? 0),
            // Paciente nuevo: el job lo da de alta antes de crear la cita.
            'pending_patient' => $session['pending_patient'] ?? null,
            // Carnet corregido en el resumen: el job lo guarda en la ficha.
            'ci_override'     => $session['ci_override'] ?? null,
            'doctor_id'       => (int) $session['doctor_id'],
            'specialty_id'    => (int) $session['specialty_id'],
            'specialty'       => $this->name('specialties', $session['specialty_id']),
            'date'            => $session['date'],
            'time'            => $session['time'],
            'duration'        => $this->duration(),
        ]); // Cola por defecto, no la de WhatsApp: tiene un solo worker, y la
        // verificación de seguros (hasta ~50 s) frenaría todos los envíos.

        FlowSession::forget($token);

        return $this->success($token, [
            'status'         => 'pending',
            'patient_status' => $session['patient_status'] ?? null,
            'patient_id'     => ($session['patient_id'] ?? 0) ?: null,
            'patient_name'   => $session['pending_patient']['input']['name'] ?? null,
            'relation'       => $session['relation'] ?? null,
            'specialty'      => $this->name('specialties', $session['specialty_id']),
            'doctor_id'      => (int) $session['doctor_id'],
            'doctor_name'    => $this->name('doctors', $session['doctor_id']),
            'date'           => $session['date'],
            'time'           => $session['time'],
        ]);
    }

    protected function hasPatient(string $token): bool
    {
        $session = FlowSession::get($token);

        return ! empty($session['patient_id']) || ! empty($session['pending_patient']);
    }

    /**
     * El lookup de la pantalla anterior, para no repetir la consulta a SMD al
     * confirmar. Solo si es la misma cédula: el cliente puede mandar otra.
     */
    protected function rememberLookup(string $token, array $found): void
    {
        if ($token !== '') {
            FlowSession::merge($token, ['lookup' => $found]);
        }
    }

    protected function recalledLookup(string $token, string $ci): ?array
    {
        $found = FlowSession::get($token)['lookup'] ?? null;

        return $found && ($found['ci'] ?? null) === $this->resolver->normalizeCi($ci) ? $found : null;
    }

    /** Horarios del doctor elegido, guardados en la sesión al consultarlos. */
    protected function sessionSlots(array $session): array
    {
        return (array) ($session['doctor_slots'] ?? []);
    }

    protected function name(string $table, $id): string
    {
        return trim((string) DB::table($table)->where('id', (int) $id)->value('name'));
    }

    protected function duration(): int
    {
        return $this->booking->duration();
    }

    /** "Lun 13/10" */
    protected function dayLabel(string $date): string
    {
        $d = Carbon::parse($date)->locale('es');

        return ucfirst(rtrim($d->isoFormat('ddd'), '.')).' '.$d->format('d/m');
    }

    /** "Lun 13/10 · 09:00" */
    protected function label(string $dateTime): string
    {
        [$date, $time] = explode(' ', $dateTime) + [1 => ''];

        return $this->dayLabel($date).' · '.$time;
    }

    protected function confirm(string $mode, int $patientId, string $heading, string $name): array
    {
        return $this->screen('CONFIRM', [
            'mode'       => $mode,
            'patient_id' => (string) $patientId,
            'heading'    => $heading,
            'detail'     => $name,
            'full_name'  => '',
            'ci'         => '',
            'birthdate'  => '',
            'relation'   => '',
        ]);
    }

    /**
     * CONFIRM es terminal: un fallo se muestra ahí mismo (snackbar) en vez de
     * rutear hacia atrás, que el routing_model de Meta no permite desde un final.
     */
    protected function confirmError(array $data, string $message): array
    {
        $fields = ['mode', 'patient_id', 'heading', 'detail', 'full_name', 'ci', 'birthdate', 'relation'];

        return $this->screen('CONFIRM', array_merge(
            array_fill_keys($fields, ''),
            array_intersect_key(array_map('strval', $data), array_flip($fields)),
        ), $message);
    }

    /**
     * Cierre: los params viajan al webhook dentro de `nfm_reply.response_json`.
     */
    protected function success(string $token, array $params): array
    {
        if (! empty($params['patient_id'])) {
            $params['patient_name'] = $this->name('persons', $params['patient_id']);
        }

        return [
            'screen' => 'SUCCESS',
            'data'   => [
                'extension_message_response' => [
                    'params' => array_filter(
                        ['flow_token' => $token, 'flow' => 'paciente_tercero'] + $params,
                        fn ($v) => $v !== null && $v !== '',
                    ),
                ],
            ],
        ];
    }

    protected function screen(string $screen, array $data, ?string $error = null): array
    {
        if ($error) {
            $data['error_message'] = $error;
        }

        return ['screen' => $screen, 'data' => $data];
    }
}
