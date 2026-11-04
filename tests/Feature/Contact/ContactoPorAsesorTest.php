<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\User\Models\User;

/**
 * C2 (spec docs/specs/tablero-por-asesor.md, D13b): los ejecutivos tienen
 * permiso individual para no pelearse por los contactos. La lista ya filtraba;
 * ahora la ficha, la edición y cada ruta de un contacto ajeno dan 404, y el
 * «Asesor Asignado» lo cambian solo Administrador y Supervisores.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    // Bulmaro como ejecutivo común: acá no es encargado nacional (ver EncargadoNacionalTest).
    config(['reporting.encargados_nacionales' => []]);

    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $this->mariano = User::where('name', 'Mariano Pinell')->firstOrFail();

    $nuevo = fn (User $duenio) => DB::table('persons')->insertGetId([
        'name' => 'Contacto '.uniqid(), 'user_id' => $duenio->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->deBulmaro = $nuevo($this->bulmaro);
    $this->deEduardo = $nuevo($this->eduardo);
});

function asesorDelContacto(int $id): ?int
{
    $id = DB::table('persons')->where('id', $id)->value('user_id');

    return $id === null ? null : (int) $id;
}

it('cada ruta de un contacto ajeno le responde 404 a un ejecutivo', function () {
    $rutas = [
        ['GET', route('admin.contacts.persons.view', $this->deEduardo)],
        ['GET', route('admin.contacts.persons.edit', $this->deEduardo)],
        ['PUT', route('admin.contacts.persons.update', $this->deEduardo)],
        ['DELETE', route('admin.contacts.persons.delete', $this->deEduardo)],
        ['GET', route('admin.contacts.persons.activities.index', $this->deEduardo)],
        ['GET', route('admin.contacts.persons.files.index', $this->deEduardo)],
        ['POST', route('admin.contacts.persons.tags.attach', $this->deEduardo)],
        ['DELETE', route('admin.contacts.persons.tags.detach', $this->deEduardo)],
    ];

    $estados = collect($rutas)->mapWithKeys(fn ($r) => [$r[0].' '.$r[1] => test()->actingAs($this->bulmaro, 'user')
        ->withHeaders(['Accept' => 'application/json'])->json($r[0], $r[1], ['name' => 'x'])->status()]);

    // El DELETE lo corta antes el ACL de Krayin (los ejecutivos no tienen
    // permiso de borrar contactos): 401 también es «no», y el contacto sigue.
    expect($estados->reject(fn ($s, $ruta) => $s === 404 || (str_starts_with($ruta, 'DELETE') && ! str_contains($ruta, '/tags') && $s === 401))->all())->toBe([])
        ->and(DB::table('persons')->where('id', $this->deEduardo)->exists())->toBeTrue();
});

it('un ejecutivo abre la ficha de su contacto', function () {
    test()->actingAs($this->bulmaro, 'user')->get(route('admin.contacts.persons.view', $this->deBulmaro))->assertOk();
});

it('el borrado masivo no le deja a un ejecutivo borrar contactos ajenos', function () {
    test()->actingAs($this->bulmaro, 'user')->withHeaders(['Accept' => 'application/json'])
        ->post(route('admin.contacts.persons.mass_delete'), ['indices' => [$this->deEduardo]]);

    expect(DB::table('persons')->where('id', $this->deEduardo)->exists())->toBeTrue();
});

it('un ejecutivo no cambia el «Asesor Asignado» y editar su contacto no se lo quita', function () {
    $this->actingAs($this->bulmaro, 'user');
    app(PersonRepository::class)->update(['user_id' => $this->eduardo->id, 'entity_type' => 'persons'], $this->deBulmaro);
    expect(asesorDelContacto($this->deBulmaro))->toBe($this->bulmaro->id);

    // Edición en línea de otro campo (no manda user_id): el asesor no se borra.
    app(PersonRepository::class)->update(['job_title' => 'Gerente', 'entity_type' => 'persons'], $this->deBulmaro);
    expect(asesorDelContacto($this->deBulmaro))->toBe($this->bulmaro->id);
});

it('el supervisor sí cambia el «Asesor Asignado», y sin sesión (reparto) también', function () {
    $this->actingAs($this->mariano, 'user');
    app(PersonRepository::class)->update(['user_id' => $this->eduardo->id, 'entity_type' => 'persons'], $this->deBulmaro);
    expect(asesorDelContacto($this->deBulmaro))->toBe($this->eduardo->id);

    auth()->guard('user')->logout();
    app(PersonRepository::class)->update(['user_id' => $this->bulmaro->id, 'entity_type' => 'persons'], $this->deBulmaro);
    expect(asesorDelContacto($this->deBulmaro))->toBe($this->bulmaro->id);
});

it('el formulario del contacto ofrece cambiar el asesor solo a quien puede', function () {
    $deBulmaro = test()->actingAs($this->bulmaro, 'user')->get(route('admin.contacts.persons.edit', $this->deBulmaro))->assertOk()->getContent();
    $deMariano = test()->actingAs($this->mariano, 'user')->get(route('admin.contacts.persons.edit', $this->deBulmaro))->assertOk()->getContent();

    expect($deMariano)->toContain('name="user_id"')
        ->and($deBulmaro)->not->toContain('name="user_id"');
});
