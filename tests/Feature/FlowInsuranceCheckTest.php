<?php

/*
|--------------------------------------------------------------------------
| Formulario de agendar: cédula obligatoria y seguro contra todas las aseguradoras
|--------------------------------------------------------------------------
| - "Para mí" o un paciente a cargo SIN cédula → pantalla PATIENT_CI.
| - Al agendar, la cédula se verifica contra Alianza, Nacional Vida y la
|   Membresía: queda en la ficha, en el motivo (Sofo y SMD) y, si hay
|   cobertura vigente, en la confirmación al paciente.
| Las aseguradoras se reemplazan por drivers falsos (el servicio los pide al
| contenedor), así no se depende de sus APIs.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';
require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Webkul\Admin\Services\Insurance\Contracts\InsuranceDriverInterface;
use Webkul\Admin\Services\Insurance\Drivers\AlianzaDriver;
use Webkul\Admin\Services\Insurance\Drivers\NacionalVidaDriver;
use Webkul\Admin\Services\Insurance\Drivers\OdontokingMembershipDriver;
use Webkul\Admin\Services\InsuranceService;
use Webkul\Whatsapp\Jobs\BookFlowAppointment;
use Webkul\Whatsapp\Jobs\ReplyAfterAgent;
use Webkul\Whatsapp\Services\Flows\FlowSession;
use Webkul\Whatsapp\Services\Flows\FlowToken;

beforeEach(function () {
    $this->smd = auditBootFakes();
    config(['services.insurance.cache_enabled' => false]);
    Queue::fake([ReplyAfterAgent::class]);

    $this->ciAttr = DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id');
    $this->seguroAttr = DB::table('attributes')->where('code', 'seguro_paciente')->value('id');
    $this->option = fn (string $name) => (int) DB::table('attribute_options')->where('attribute_id', $this->seguroAttr)->where('name', $name)->value('id');

    do {
        $this->ci = (string) rand(100000000, 999999999);
    } while (DB::table('attribute_values')->where('attribute_id', $this->ciAttr)->where('text_value', 'like', '%'.$this->ci.'%')->exists());

    // Por defecto ninguna aseguradora lo tiene.
    fakeInsurer(AlianzaDriver::class, 'NO_REGISTRADO');
    fakeInsurer(NacionalVidaDriver::class, 'NO_REGISTRADO');
    fakeInsurer(OdontokingMembershipDriver::class, 'NO_REGISTRADO');
});

function fakeInsurer(string $class, string $status): void
{
    app()->instance($class, new class($status) implements InsuranceDriverInterface
    {
        public int $calls = 0;

        public function __construct(public string $status) {}

        public function verify(object $person, string $ci): array
        {
            $this->calls++;

            if ($this->status === 'THROW') {
                throw new RuntimeException('timeout');
            }

            return ['status' => $this->status, 'message' => $this->status, 'badge' => null, 'success' => true, 'data' => null];
        }
    });
}

function withCi(int $personId, string $ci): void
{
    DB::table('attribute_values')->updateOrInsert(
        ['attribute_id' => test()->ciAttr, 'entity_id' => $personId, 'entity_type' => 'persons'],
        ['text_value' => $ci],
    );
}

/*
| Verificación contra todas las aseguradoras
*/

it('verifica contra todas las aseguradoras y deja en la ficha las que lo tienen', function () {
    fakeInsurer(AlianzaDriver::class, 'VIGENTE');
    $person = auditPerson();
    withCi($person->id, $this->ci);

    // Tenía "No tiene" cargado: deja de ser cierto.
    DB::table('attribute_values')->insert(['attribute_id' => $this->seguroAttr, 'entity_id' => $person->id, 'entity_type' => 'persons', 'text_value' => (string) ($this->option)('No tiene')]);

    $summary = app(InsuranceService::class)->verifyAll($person->id);

    expect($summary['status'])->toBe('VIGENTE')
        ->and(array_column($summary['found'], 'seguro_name'))->toBe(['Alianza'])
        ->and(DB::table('attribute_values')->where('attribute_id', $this->seguroAttr)->where('entity_id', $person->id)->value('text_value'))
        ->toBe((string) ($this->option)('Alianza'))
        ->and(DB::table('person_insurance_statuses')->where('person_id', $person->id)->pluck('status', 'seguro_name')->all())
        ->toMatchArray(['Alianza' => 'VIGENTE', 'Nacional Vida' => 'NO_REGISTRADO', 'Membresia Odontoking' => 'NO_REGISTRADO'])
        ->and(DB::table('persons')->where('id', $person->id)->value('insurance_status'))->toBe('VIGENTE');

    // Cada aseguradora una sola vez, y el estado del perfil queda en VIGENTE
    // aunque la última consultada diera "no registrado".
    expect(app(NacionalVidaDriver::class)->calls)->toBe(1)
        ->and(app(AlianzaDriver::class)->calls)->toBe(1)
        ->and(DB::table('attribute_values')->where('entity_id', $person->id)->where('entity_type', 'persons')
            ->where('attribute_id', DB::table('attributes')->where('code', 'estado_seguro_paciente')->value('id'))->value('integer_value'))
        ->toBe((int) DB::table('attribute_options')->where('attribute_id', DB::table('attributes')->where('code', 'estado_seguro_paciente')->value('id'))->where('name', 'Vigente')->value('id'));
});

it('sin cédula no consulta a ninguna aseguradora', function () {
    $summary = app(InsuranceService::class)->verifyAll(auditPerson()->id);

    expect($summary['status'])->toBe('INDETERMINADO')
        ->and(app(AlianzaDriver::class)->calls)->toBe(0);
});

/*
| Al agendar: motivo y confirmación
*/

function bookWithInsurance(object $t, int $personId): object
{
    $doctorId = auditDoctor(['name' => 'Dra Seguro '.uniqid()]);
    $uid = DB::table('doctors')->where('id', $doctorId)->value('unique_id');
    $day = Carbon::tomorrow(config('app.timezone'));
    auditShift($doctorId, $day, '08:00', '12:00');
    $t->smd['availability'] = auditSmdAvailability($uid, $day->copy()->setTime(9, 0), $day->copy()->setTime(12, 0));

    BookFlowAppointment::dispatchSync([
        'conversation_id' => 0, 'responsible_id' => $personId, 'patient_id' => $personId,
        'doctor_id'       => $doctorId, 'specialty_id' => 0, 'specialty' => 'Endodoncia',
        'date'            => $day->toDateString(), 'time' => '10:00', 'duration' => 60,
    ]);

    return DB::table('activities')
        ->join('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
        ->where('activity_participants.person_id', $personId)
        ->where('activities.schedule_from', $day->toDateString().' 10:00:00')
        ->first(['activities.comment']);
}

it('el seguro encontrado va al motivo y la cobertura vigente a la confirmación', function () {
    fakeInsurer(AlianzaDriver::class, 'VIGENTE');
    $person = auditPerson();
    withCi($person->id, $this->ci);

    $cita = bookWithInsurance($this, $person->id);

    expect($cita->comment)->toEndWith(' - Seguro: Alianza VIGENTE');
    Queue::assertPushed(ReplyAfterAgent::class, fn ($job) => str_contains($job->text, '✅ Cita confirmada')
        && str_contains($job->text, '✅ Seguro válido: Alianza (vigente)'));
});

it('en mora va al motivo y al paciente se le dice que no es válido y qué hacer', function () {
    fakeInsurer(NacionalVidaDriver::class, 'EN_MORA');
    $person = auditPerson();
    withCi($person->id, $this->ci);

    $cita = bookWithInsurance($this, $person->id);

    expect($cita->comment)->toEndWith(' - Seguro: Nacional Vida EN MORA');
    Queue::assertPushed(ReplyAfterAgent::class, fn ($job) => str_contains($job->text, '⚠️ Seguro no válido: Nacional Vida en mora.')
        && str_contains($job->text, 'al venir a la clínica podés actualizar tus datos'));
});

it('si una aseguradora no responde, la cita se agenda igual y el motivo lo aclara', function () {
    fakeInsurer(AlianzaDriver::class, 'THROW');
    $person = auditPerson();
    withCi($person->id, $this->ci);

    $cita = bookWithInsurance($this, $person->id);

    expect($cita)->not->toBeNull()
        ->and($cita->comment)->toEndWith(' - Seguro: no encontrado (sin respuesta de Alianza)');
});

/*
| Pantalla de cédula en el formulario
*/

describe('formulario', function () {
    beforeEach(function () {
        if (trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('Falta el binario openssl para simular el cifrado de Meta.');
        }

        [$this->publicPem, $this->keyPath] = bootFlowEndpoint();
        config(['whatsapp.flows.patient.ask_ci' => true]);
        $this->yo = auditPerson(['name' => 'Javi 🦷']);
        $this->token = FlowToken::issue(flowConversation($this->yo->id)->id);
    });

    afterEach(function () {
        @unlink($this->keyPath);
    });

    it('"Para mí" sin cédula pide la cédula con el nombre precargado', function () {
        $res = step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self']);

        expect($res['screen'])->toBe('PATIENT_CI')
            ->and($res['data'])->toMatchArray(['mode' => 'self', 'patient_id' => (string) $this->yo->id, 'full_name' => 'Javi 🦷']);
    });

    it('"Para mí" con la cédula ya cargada no la vuelve a pedir', function () {
        withCi($this->yo->id, $this->ci);

        expect(step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self'])['screen'])->toBe('CONFIRM');
    });

    it('una cédula inválida vuelve a pedirla', function () {
        $res = step($this->publicPem, $this->token, 'PATIENT_CI', ['mode' => 'self', 'patient_id' => (string) $this->yo->id, 'full_name' => 'Javier Mogro', 'ci' => '12']);

        expect($res['screen'])->toBe('PATIENT_CI')
            ->and($res['data']['error_message'])->toContain('cédula');
    });

    it('la cédula se guarda al confirmar, y el nombre real reemplaza al del perfil', function () {
        $confirm = step($this->publicPem, $this->token, 'PATIENT_CI', ['mode' => 'self', 'patient_id' => (string) $this->yo->id, 'full_name' => 'Javier Mogro', 'ci' => $this->ci]);

        expect($confirm['screen'])->toBe('CONFIRM')
            ->and($confirm['data']['detail'])->toBe('Javier Mogro · CI '.$this->ci)
            // Validar no escribe: recién al confirmar.
            ->and(app(\Webkul\Admin\Services\PatientIdentityResolver::class)->ciFor($this->yo->id))->toBeNull();

        step($this->publicPem, $this->token, 'CONFIRM', $confirm['data']);

        expect(app(\Webkul\Admin\Services\PatientIdentityResolver::class)->ciFor($this->yo->id))->toBe($this->ci)
            ->and(DB::table('persons')->where('id', $this->yo->id)->value('name'))->toBe('Javier Mogro')
            ->and(FlowSession::get($this->token))->toMatchArray(['patient_id' => $this->yo->id, 'patient_status' => 'self']);
    });

    it('si la cédula es de otra ficha con el mismo nombre, agenda en esa ficha y la vincula', function () {
        $ficha = auditPerson(['name' => 'Javier Mogro Peñafiel']);
        withCi($ficha->id, $this->ci);

        $confirm = step($this->publicPem, $this->token, 'PATIENT_CI', ['mode' => 'self', 'patient_id' => (string) $this->yo->id, 'full_name' => 'Javier Mogro', 'ci' => $this->ci]);
        step($this->publicPem, $this->token, 'CONFIRM', $confirm['data']);

        expect(FlowSession::get($this->token)['patient_id'])->toBe($ficha->id)
            ->and(DB::table('person_relations')->where('patient_person_id', $ficha->id)->where('responsible_person_id', $this->yo->id)->exists())->toBeTrue();
    });

    it('con ask_ci apagado (Flow viejo publicado) no manda la pantalla nueva', function () {
        config(['whatsapp.flows.patient.ask_ci' => false]);

        expect(step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self'])['screen'])->toBe('CONFIRM');
    });

    it('un formulario de prueba activa la pantalla solo para su token', function () {
        config(['whatsapp.flows.patient.ask_ci' => false]);
        FlowSession::merge($this->token, ['ask_ci' => true]);
        $otro = FlowToken::issue(flowConversation(auditPerson()->id)->id);

        expect(step($this->publicPem, $this->token, 'PATIENT_SELECT', ['choice' => 'self'])['screen'])->toBe('PATIENT_CI')
            ->and(step($this->publicPem, $otro, 'PATIENT_SELECT', ['choice' => 'self'])['screen'])->toBe('CONFIRM');
    });

    it('si la cédula figura con otro nombre, no la acepta ni revela de quién es', function () {
        $otra = auditPerson(['name' => 'Roberto Gutierrez']);
        withCi($otra->id, $this->ci);

        $res = step($this->publicPem, $this->token, 'PATIENT_CI', ['mode' => 'self', 'patient_id' => (string) $this->yo->id, 'full_name' => 'Javier Mogro', 'ci' => $this->ci]);

        expect($res['screen'])->toBe('PATIENT_CI')
            ->and($res['data']['error_message'])->toContain('otro nombre')->not->toContain('Roberto');
    });
});

/*
| Seguro declarado en el formulario (pantalla INSURANCE)
*/

it('consultar solo con la cédula no escribe en ninguna ficha', function () {
    fakeInsurer(AlianzaDriver::class, 'VIGENTE');
    $before = DB::table('attribute_values')->where('entity_id', 0)->count();

    $summary = app(InsuranceService::class)->verifyCi($this->ci);

    expect($summary['status'])->toBe('VIGENTE')
        ->and(DB::table('attribute_values')->where('entity_id', 0)->count())->toBe($before);
});

it('el resultado queda en caché: al agendar no se vuelve a consultar a la aseguradora', function () {
    fakeInsurer(AlianzaDriver::class, 'VIGENTE');
    $service = app(InsuranceService::class);
    $alianza = ($this->option)('Alianza');

    expect($service->peekCi($this->ci, [$alianza]))->toBeNull();
    $service->verifyCi($this->ci, [$alianza]);

    $person = auditPerson();
    withCi($person->id, $this->ci);
    $service->verifyAll($person->id, [$alianza]);

    expect($service->peekCi($this->ci, [$alianza])['status'])->toBe('VIGENTE')
        ->and(app(AlianzaDriver::class)->calls)->toBe(1)
        ->and(app(NacionalVidaDriver::class)->calls)->toBe(0);
});

it('al agendar con el carnet corregido, lo guarda en la ficha y verifica con ese', function () {
    fakeInsurer(OdontokingMembershipDriver::class, 'VIGENTE');
    $person = auditPerson();
    withCi($person->id, '1111111');
    $nuevo = $this->ci;

    $doctorId = auditDoctor(['name' => 'Dra Carnet '.uniqid()]);
    $uid = DB::table('doctors')->where('id', $doctorId)->value('unique_id');
    $day = Carbon::tomorrow(config('app.timezone'));
    auditShift($doctorId, $day, '08:00', '12:00');
    $this->smd['availability'] = auditSmdAvailability($uid, $day->copy()->setTime(9, 0), $day->copy()->setTime(12, 0));

    BookFlowAppointment::dispatchSync([
        'conversation_id' => 0, 'responsible_id' => $person->id, 'patient_id' => $person->id,
        'doctor_id'       => $doctorId, 'specialty_id' => 0, 'specialty' => 'Endodoncia',
        'date'            => $day->toDateString(), 'time' => '10:00', 'duration' => 60, 'ci_override' => $nuevo,
    ]);

    expect(app(\Webkul\Admin\Services\PatientIdentityResolver::class)->ciFor($person->id))->toBe($nuevo)
        ->and(DB::table('activities')->join('activity_participants', 'activity_participants.activity_id', '=', 'activities.id')
            ->where('person_id', $person->id)->value('activities.comment'))->toEndWith('Seguro: Membresia Odontoking VIGENTE');
});

describe('formulario con seguro', function () {
    beforeEach(function () {
        if (trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('Falta el binario openssl para simular el cifrado de Meta.');
        }

        [$this->publicPem, $this->keyPath] = bootFlowEndpoint();
        config(['whatsapp.flows.patient.ask_ci' => true, 'whatsapp.flows.booking.days' => 3, 'whatsapp.flows.booking.duration_minutes' => 60, 'whatsapp.flows.insurance_wait_seconds' => 0]);

        $this->doctorId = auditDoctor(['name' => 'Dra Seguro Flow '.uniqid()]);
        $uid = DB::table('doctors')->where('id', $this->doctorId)->value('unique_id');
        $this->specialtyId = DB::table('specialties')->insertGetId(['name' => 'Zq Seguro '.uniqid(), 'slug' => 'zq-s-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('doctor_specialty')->insert(['doctor_id' => $this->doctorId, 'specialty_id' => $this->specialtyId]);
        $this->day = Carbon::tomorrow(config('app.timezone'));
        auditShift($this->doctorId, $this->day, '08:00', '12:00');
        $this->smd['availability'] = auditSmdAvailability($uid, $this->day->copy()->setTime(9, 0), $this->day->copy()->setTime(12, 0));

        $this->yo = auditPerson(['name' => 'Paciente Seguro Flow']);
        withCi($this->yo->id, $this->ci);
        $this->token = FlowToken::issue(flowConversation($this->yo->id)->id);
    });

    afterEach(function () {
        @unlink($this->keyPath);
    });

    /** Para mí → especialidad → doctor → día → hora → resumen. */
    function summaryFor(object $t): array
    {
        $confirm = step($t->publicPem, $t->token, 'PATIENT_SELECT', ['choice' => 'self']);
        $next = step($t->publicPem, $t->token, 'CONFIRM', $confirm['data']);
        expect($next['screen'])->toBe('SPECIALTY');

        step($t->publicPem, $t->token, 'SPECIALTY', ['specialty' => (string) $t->specialtyId]);
        step($t->publicPem, $t->token, 'DOCTOR', ['doctor' => (string) $t->doctorId]);
        step($t->publicPem, $t->token, 'SLOT_DATE', ['date' => $t->day->toDateString()]);

        return step($t->publicPem, $t->token, 'SLOT_TIME', ['time' => '10:00']);
    }

    it('no pregunta el seguro: verifica todas con el carnet y lo muestra en "Revisá tu cita"', function () {
        fakeInsurer(OdontokingMembershipDriver::class, 'VIGENTE');

        $summary = summaryFor($this);

        expect($summary['screen'])->toBe('SUMMARY')
            ->and($summary['data']['detail'])->toContain('✅ Seguro válido: Membresia Odontoking (vigente)')
            ->and($summary['data']['show_ci'])->toBeFalse()
            ->and(app(AlianzaDriver::class)->calls)->toBe(1)
            ->and(app(NacionalVidaDriver::class)->calls)->toBe(1);
    });

    it('si no encuentra seguro, muestra el carnet para corregirlo', function () {
        $summary = summaryFor($this);

        expect($summary['data']['detail'])->toContain('No encontramos seguro ni membresía con el carnet '.$this->ci)
            ->toContain('corregilo abajo')
            ->and($summary['data'])->toMatchArray(['show_ci' => true, 'ci' => $this->ci]);
    });

    it('en mora no es válido: muestra el carnet y qué hacer', function () {
        fakeInsurer(NacionalVidaDriver::class, 'EN_MORA');

        $summary = summaryFor($this);

        expect($summary['data']['detail'])->toContain('⚠️ Seguro no válido: Nacional Vida en mora.')
            ->toContain('actualizar tus datos al venir a la clínica')
            ->and($summary['data']['show_ci'])->toBeTrue();
    });

    it('si las aseguradoras no responden, lo dice (sin pedir el carnet)', function () {
        foreach ([AlianzaDriver::class, NacionalVidaDriver::class, OdontokingMembershipDriver::class] as $driver) {
            fakeInsurer($driver, 'THROW');
        }

        $summary = summaryFor($this);

        expect($summary['data']['detail'])->toContain('No pudimos verificar tu seguro en este momento')
            ->and($summary['data']['show_ci'])->toBeFalse();
    });

    it('si todavía no respondió, dice "verificando"', function () {
        Queue::fake([\Webkul\Whatsapp\Jobs\VerifyFlowInsurance::class]);

        expect(summaryFor($this)['data']['detail'])->toContain('todavía estamos verificando');
    });

    it('corregir el carnet vuelve a verificar y NO agenda todavía', function () {
        Queue::fake([BookFlowAppointment::class]);
        summaryFor($this);

        // El carnet correcto está en el padrón de la membresía.
        $correcto = (string) ((int) $this->ci + 7);
        app()->instance(OdontokingMembershipDriver::class, new class($correcto) implements InsuranceDriverInterface
        {
            public int $calls = 0;

            public function __construct(public string $ok) {}

            public function verify(object $person, string $ci): array
            {
                $this->calls++;

                return ['status' => $ci === $this->ok ? 'VIGENTE' : 'NO_REGISTRADO', 'message' => '', 'badge' => null, 'success' => true, 'data' => null];
            }
        });

        $res = step($this->publicPem, $this->token, 'SUMMARY', ['confirm' => 'si', 'ci' => $correcto]);

        expect($res['screen'])->toBe('SUMMARY')
            ->and($res['data']['detail'])->toContain('✅ Seguro válido: Membresia Odontoking (vigente)')
            ->and($res['data']['error_message'])->toContain('Actualizamos tu carnet');
        Queue::assertNotPushed(BookFlowAppointment::class);

        // Confirmar sin cambios ahora sí agenda, con el carnet corregido.
        step($this->publicPem, $this->token, 'SUMMARY', ['confirm' => 'si', 'ci' => '']);
        Queue::assertPushed(BookFlowAppointment::class, fn ($job) => $job->booking['ci_override'] === $correcto);
    });

    it('un carnet corregido que es de otra persona no se acepta', function () {
        Queue::fake([BookFlowAppointment::class]);
        summaryFor($this);
        $ajeno = auditPerson(['name' => 'Roberto Gutierrez']);
        $ajenoCi = (string) ((int) $this->ci + 3);
        withCi($ajeno->id, $ajenoCi);

        $res = step($this->publicPem, $this->token, 'SUMMARY', ['confirm' => 'si', 'ci' => $ajenoCi]);

        expect($res['data']['error_message'])->toContain('otro nombre')
            ->and(json_encode($res))->not->toContain('Roberto');
        Queue::assertNotPushed(BookFlowAppointment::class);
    });

    it('con el Flow viejo publicado, el resumen no manda los campos nuevos', function () {
        config(['whatsapp.flows.patient.ask_ci' => false]);

        $summary = summaryFor($this);

        expect($summary['data'])->not->toHaveKey('ci')->not->toHaveKey('show_ci')
            ->and($summary['data']['detail'])->not->toContain('Seguro válido')->not->toContain('No encontramos seguro')->not->toContain('verificando');
    });
});
