<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

/**
 * El boton de WhatsApp del modal de detalle de cita arma el enlace wa.me con
 * `person_whatsapp`, que expone el endpoint del calendario. wa.me exige el
 * numero completo (codigo de pais + numero, solo digitos).
 */
function makeWhatsappCalendarAppointment(array $emails, ?array $contactNumbers, Carbon $from): int
{
    $doctorId = DB::table('doctors')->insertGetId([
        'name'       => 'Dr. WhatsApp '.uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $activityId = DB::table('activities')->insertGetId([
        'type'          => 'meeting',
        'title'         => 'Cita WhatsApp '.uniqid(),
        'schedule_from' => $from->format('Y-m-d H:i:s'),
        'schedule_to'   => $from->copy()->addHour()->format('Y-m-d H:i:s'),
        'user_id'       => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    DB::table('doctor_activities')->insert(['doctor_id' => $doctorId, 'activity_id' => $activityId]);

    $personId = DB::table('persons')->insertGetId([
        'name'            => 'Paciente WhatsApp '.uniqid(),
        'emails'          => json_encode($emails),
        'contact_numbers' => $contactNumbers === null ? null : json_encode($contactNumbers),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    DB::table('activity_participants')->insert(['activity_id' => $activityId, 'person_id' => $personId]);

    return $activityId;
}

function fetchWhatsappAppointment(int $activityId, Carbon $from): ?array
{
    $response = test()->get(route('admin.activities.get', [
        'view_type'     => 'calendar',
        'calendar_mode' => 'doctor',
        'calendar_view' => 'day',
        'start'         => $from->toDateString(),
    ]));

    $response->assertStatus(200);

    return collect($response->json('appointments'))->firstWhere('id', $activityId);
}

beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    config(['smd.whatsapp_country_code' => '591']);
});

it('toma el numero del email @whatsapp, que ya viene normalizado', function () {
    $from = Carbon::tomorrow()->setTime(9, 0);

    $activityId = makeWhatsappCalendarAppointment(
        [['value' => '59167736563@whatsapp.sofopolis.net', 'label' => 'work']],
        [['value' => '67736563', 'label' => 'work']],
        $from
    );

    expect(fetchWhatsappAppointment($activityId, $from)['person_whatsapp'])->toBe('59167736563');
});

it('antepone el codigo de pais a un telefono guardado en formato local', function () {
    $from = Carbon::tomorrow()->setTime(10, 0);

    $activityId = makeWhatsappCalendarAppointment(
        [['value' => 'paciente@correo.com', 'label' => 'work']],
        [['value' => '67736563', 'label' => 'work']],
        $from
    );

    expect(fetchWhatsappAppointment($activityId, $from)['person_whatsapp'])->toBe('59167736563');
});

it('limpia espacios, guiones y el signo + del telefono de contacto', function () {
    $from = Carbon::tomorrow()->setTime(11, 0);

    $activityId = makeWhatsappCalendarAppointment(
        [['value' => 'paciente@correo.com', 'label' => 'work']],
        [['value' => '+591 6773-6563', 'label' => 'work']],
        $from
    );

    expect(fetchWhatsappAppointment($activityId, $from)['person_whatsapp'])->toBe('59167736563');
});

it('devuelve null cuando el paciente no tiene ningun telefono utilizable', function () {
    $from = Carbon::tomorrow()->setTime(12, 0);

    $activityId = makeWhatsappCalendarAppointment(
        [['value' => 'paciente@correo.com', 'label' => 'work']],
        null,
        $from
    );

    expect(fetchWhatsappAppointment($activityId, $from)['person_whatsapp'])->toBeNull();
});

it('no expone las columnas crudas que solo sirven para resolver el numero', function () {
    $from = Carbon::tomorrow()->setTime(13, 0);

    $activityId = makeWhatsappCalendarAppointment(
        [['value' => '59167736563@whatsapp.sofopolis.net', 'label' => 'work']],
        null,
        $from
    );

    expect(fetchWhatsappAppointment($activityId, $from))
        ->not->toHaveKey('person_email')
        ->not->toHaveKey('person_contact_number');
});

it('el calendario renderiza el boton WhatsApp con sus cuatro plantillas', function () {
    test()->get(route('admin.activities.index'))
        ->assertStatus(200)
        ->assertSee('>WhatsApp<', false)
        ->assertSee('openWhatsapp', false)
        ->assertSee('Recordatorio de cita', false)
        ->assertSee('Confirmación de Cita', false)
        ->assertSee('Encuesta', false)
        ->assertSee('Recordatorio Primera Cita', false);
});
