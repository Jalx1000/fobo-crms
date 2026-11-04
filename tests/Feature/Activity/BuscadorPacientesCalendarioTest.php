<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\User\Models\User;

/**
 * Buscador de pacientes en el calendario.
 *
 * Desde la barra del calendario se busca un paciente por nombre, teléfono o
 * carnet (CI/NIT) y se ven sus citas -las próximas primero-, para saltar al día
 * de una de ellas sin recorrer semanas a mano.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(User::findOrFail(1), 'user');

    $this->ci = '71'.random_int(10000, 99999);
    $this->tel = '+5916'.random_int(1000000, 9999999);

    $this->paciente = Event::fakeFor(fn () => app(PersonRepository::class)->create([
        'entity_type'     => 'persons',
        'name'            => 'Paciente Calendario '.uniqid(),
        'contact_numbers' => [['value' => $this->tel, 'label' => 'work']],
        'nit'             => $this->ci,
    ]));

    $this->doctor = DB::table('doctors')->insertGetId(['name' => 'Dra. Test '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);

    $this->proxima = cita($this, now()->addDays(3)->setTime(10, 0), 'meeting');
    $this->pasada = cita($this, now()->subDays(10)->setTime(9, 0), 'meeting');
});

function cita($test, $desde, string $tipo, ?int $personId = null): int
{
    $id = DB::table('activities')->insertGetId([
        'title'         => 'Consulta', 'type' => $tipo, 'is_done' => 0, 'user_id' => 1,
        'schedule_from' => $desde, 'schedule_to' => (clone $desde)->addMinutes(30),
        'created_at'    => now(), 'updated_at' => now(),
    ]);

    DB::table('activity_participants')->insert(['activity_id' => $id, 'person_id' => $personId ?? $test->paciente->id]);
    DB::table('doctor_activities')->insert(['activity_id' => $id, 'doctor_id' => $test->doctor]);

    return $id;
}

function buscarPaciente(string $q): array
{
    return test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.activities.buscar-paciente', ['q' => $q]))
        ->assertOk()
        ->json('pacientes');
}

it('encuentra al paciente por CI con sus citas, la próxima primero', function () {
    $paciente = collect(buscarPaciente($this->ci))->firstWhere('id', $this->paciente->id);

    expect($paciente)->not->toBeNull()
        ->and(array_column($paciente['citas'], 'id'))->toBe([$this->proxima, $this->pasada])
        ->and($paciente['citas'][0]['doctor_id'])->toBe($this->doctor)
        ->and($paciente['citas'][0]['proxima'])->toBeTrue()
        ->and($paciente['citas'][1]['proxima'])->toBeFalse();
});

it('encuentra por teléfono escrito con espacios', function () {
    $conEspacios = substr($this->tel, 4, 3).' '.substr($this->tel, 7);

    expect(collect(buscarPaciente($conEspacios))->pluck('id'))->toContain($this->paciente->id);
});

it('encuentra por nombre', function () {
    expect(collect(buscarPaciente($this->paciente->name))->pluck('id'))->toContain($this->paciente->id);
});

it('muestra el teléfono y el CI de cada paciente', function () {
    $paciente = collect(buscarPaciente($this->ci))->firstWhere('id', $this->paciente->id);

    expect($paciente['telefono'])->toBe($this->tel)
        ->and($paciente['ci'])->toBe($this->ci);
});

it('no incluye bloqueos de agenda (time_off) como citas', function () {
    $bloqueo = cita($this, now()->addDay()->setTime(8, 0), 'time_off');

    $paciente = collect(buscarPaciente($this->ci))->firstWhere('id', $this->paciente->id);

    expect(array_column($paciente['citas'], 'id'))->not->toContain($bloqueo);
});

it('un paciente sin citas aparece igual, con la lista vacía', function () {
    $otro = Event::fakeFor(fn () => app(PersonRepository::class)->create([
        'entity_type' => 'persons', 'name' => 'Sin citas '.uniqid(), 'nit' => '55'.random_int(100000, 999999),
    ]));

    $paciente = collect(buscarPaciente($otro->nit))->firstWhere('id', $otro->id);

    expect($paciente)->not->toBeNull()->and($paciente['citas'])->toBe([]);
});

it('con menos de 3 caracteres no busca', function () {
    expect(buscarPaciente('7'))->toBe([]);
});

it('el calendario muestra la caja de búsqueda de pacientes', function () {
    $this->get(route('admin.activities.index'))
        ->assertOk()
        ->assertSee('Buscar paciente: nombre, teléfono o CI', false)
        ->assertSee(route('admin.activities.buscar-paciente'), false);
});
