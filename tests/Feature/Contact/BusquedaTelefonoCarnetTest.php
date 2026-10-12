<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\User\Models\User;

/**
 * Buscar por teléfono y por carnet de identidad (CI) en contactos y pedidos.
 *
 * El CI vive en el atributo `nit` del contacto (el agente guarda ahí «NIT o
 * Carnet»), que ningún buscador miraba. El teléfono ya se buscaba, pero solo
 * tal cual está guardado («+59170760650»): escrito con espacios o guiones
 * («707 60650», «+591 70760650») no aparecía.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(User::findOrFail(1), 'user');

    $this->ci = '81'.random_int(10000, 99999);
    $this->tel = '+5917'.random_int(1000000, 9999999);

    $this->person = Event::fakeFor(fn () => app(PersonRepository::class)->create([
        'entity_type'     => 'persons',
        'name'            => 'Busqueda '.uniqid(),
        'contact_numbers' => [['value' => $this->tel, 'label' => 'work']],
        'nit'             => $this->ci,
    ]));

    $this->lead = DB::table('leads')->insertGetId([
        'title'                  => 'Pedido busqueda '.uniqid(),
        'person_id'              => $this->person->id,
        'user_id'                => 1,
        'status'                 => 1,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
});

function buscarEnGrid(string $ruta, string $termino): array
{
    return test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
        ->get(route($ruta, ['filters' => ['all' => [$termino]], 'pagination' => ['page' => 1, 'per_page' => 50]]))
        ->assertOk()
        ->json('records');
}

function buscarEnKanban(string $termino): array
{
    $columnas = test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.leads.get', ['pipeline_id' => 1, 'q' => $termino]))
        ->assertOk()
        ->json();

    return collect($columnas)->flatMap(fn ($c) => $c['leads']['data'] ?? [])->pluck('id')->all();
}

it('contactos: encuentra por CI', function () {
    expect(collect(buscarEnGrid('admin.contacts.persons.index', $this->ci))->pluck('id'))
        ->toContain($this->person->id);
});

it('contactos: encuentra por teléfono escrito con espacios y prefijo', function () {
    $conEspacios = '+591 '.substr($this->tel, 4, 3).' '.substr($this->tel, 7);

    expect(collect(buscarEnGrid('admin.contacts.persons.index', $conEspacios))->pluck('id'))
        ->toContain($this->person->id);
});

it('contactos: el listado muestra la columna CI / NIT', function () {
    $fila = collect(buscarEnGrid('admin.contacts.persons.index', $this->ci))->firstWhere('id', $this->person->id);

    expect($fila['nit'] ?? null)->toBe($this->ci);
});

it('pedidos (tabla): encuentra por CI del contacto', function () {
    expect(collect(buscarEnGrid('admin.leads.index', $this->ci))->pluck('id'))
        ->toContain($this->lead);
});

it('pedidos (tabla): encuentra por teléfono sin el +591', function () {
    expect(collect(buscarEnGrid('admin.leads.index', substr($this->tel, 4)))->pluck('id'))
        ->toContain($this->lead);
});

it('pedidos (kanban): encuentra por CI', function () {
    expect(buscarEnKanban($this->ci))->toContain($this->lead);
});

it('pedidos (kanban): encuentra por teléfono con espacios', function () {
    $conEspacios = substr($this->tel, 4, 3).' '.substr($this->tel, 7);

    expect(buscarEnKanban($conEspacios))->toContain($this->lead);
});

it('pedidos (kanban): sigue encontrando por título y nombre', function () {
    $titulo = DB::table('leads')->where('id', $this->lead)->value('title');

    expect(buscarEnKanban($titulo))->toContain($this->lead)
        ->and(buscarEnKanban($this->person->name))->toContain($this->lead);
});

it('un CI que no existe no trae nada', function () {
    expect(buscarEnKanban('99999999999'))->not->toContain($this->lead);
});
