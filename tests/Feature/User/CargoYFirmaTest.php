<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\User\Models\User;

/**
 * Cargo y firma del asesor.
 *
 * Los dos van en la firma de lo que el asesor manda. Lo que se protege acá es
 * que la firma -un archivo- no se pierda en las ediciones que no la tocan, y
 * que el admin y el propio asesor puedan cargarla por sus dos caminos.
 */
beforeEach(function () {
    test()->actingAs(getDefaultAdmin());

    $this->usuario = User::create([
        'name'            => 'Firma '.uniqid(),
        'email'           => 'firma'.uniqid().'@test.local',
        'password'        => bcrypt('secreto123'),
        'role_id'         => getDefaultAdmin()->role_id,
        'status'          => 1,
        'view_permission' => 'global',
    ]);
});

afterEach(function () {
    if (! isset($this->usuario)) {
        return;
    }

    Storage::deleteDirectory('admins/'.$this->usuario->id);

    DB::table('user_groups')->where('user_id', $this->usuario->id)->delete();
    DB::table('users')->where('id', $this->usuario->id)->delete();
});

function payloadUsuario(User $u, array $extra = []): array
{
    return array_merge([
        'name'            => $u->name,
        'email'           => $u->email,
        'role_id'         => $u->role_id,
        'status'          => 1,
        'view_permission' => 'global',
    ], $extra);
}

it('el admin carga cargo y firma desde Configuración → Usuarios', function () {
    $firma = UploadedFile::fake()->image('firma.png', 400, 120);

    test()->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->put(route('admin.settings.users.update', $this->usuario->id), payloadUsuario($this->usuario, [
            'cargo' => 'Ejecutivo comercial',
            'firma' => $firma,
        ]))->assertOk();

    $this->usuario->refresh();

    expect($this->usuario->cargo)->toBe('Ejecutivo comercial');
    expect($this->usuario->firma)->toStartWith('admins/'.$this->usuario->id.'/firma/');
    expect(Storage::exists($this->usuario->firma))->toBeTrue();
    expect($this->usuario->firma_url)->not->toBeNull();
});

it('editar solo el cargo NO borra la firma', function () {
    // El modal manda FormData con todos los campos; `firma` ausente significa
    // «no la cambiaron», no «borrala». Pisarla con null cada vez que editan el
    // cargo dejaría a los asesores sin firma sin que nadie entienda por qué.
    $this->usuario->update(['firma' => 'admins/'.$this->usuario->id.'/firma/previa.png']);
    Storage::put($this->usuario->firma, 'png');

    test()->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->put(route('admin.settings.users.update', $this->usuario->id), payloadUsuario($this->usuario, [
            'cargo' => 'Gerente',
        ]))->assertOk();

    $this->usuario->refresh();

    expect($this->usuario->cargo)->toBe('Gerente');
    expect($this->usuario->firma)->toBe('admins/'.$this->usuario->id.'/firma/previa.png');
    expect(Storage::exists($this->usuario->firma))->toBeTrue();
});

it('subir una firma nueva borra la anterior del disco', function () {
    $this->usuario->update(['firma' => 'admins/'.$this->usuario->id.'/firma/vieja.png']);
    Storage::put($this->usuario->firma, 'png');

    test()->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->put(route('admin.settings.users.update', $this->usuario->id), payloadUsuario($this->usuario, [
            'firma' => UploadedFile::fake()->image('nueva.png'),
        ]))->assertOk();

    expect(Storage::exists('admins/'.$this->usuario->id.'/firma/vieja.png'))->toBeFalse();
    expect(Storage::exists($this->usuario->refresh()->firma))->toBeTrue();
});

it('rechaza una firma que no es imagen', function () {
    test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
        ->put(route('admin.settings.users.update', $this->usuario->id), payloadUsuario($this->usuario, [
            'firma' => UploadedFile::fake()->create('firma.pdf', 100, 'application/pdf'),
        ]))->assertStatus(422);
});

it('el asesor carga su propia firma y cargo desde Mi cuenta', function () {
    test()->actingAs($this->usuario);

    test()->put(route('admin.user.account.update'), [
        'name'             => $this->usuario->name,
        'email'            => $this->usuario->email,
        'current_password' => 'secreto123',
        'password'         => '', // el formulario siempre lo manda, vacío si no se cambia
        'cargo'            => 'Asesora de ventas',
        'firma'            => [UploadedFile::fake()->image('mi-firma.png', 400, 120)],
    ])->assertRedirect();

    $this->usuario->refresh();

    expect($this->usuario->cargo)->toBe('Asesora de ventas');
    expect($this->usuario->firma)->toStartWith('admins/'.$this->usuario->id.'/firma/');
    expect(Storage::exists($this->usuario->firma))->toBeTrue();
});

it('el JSON del usuario expone firma_url para el modal', function () {
    $this->usuario->update(['firma' => 'admins/'.$this->usuario->id.'/firma/x.png']);

    $r = test()->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->getJson(route('admin.settings.users.edit', $this->usuario->id))->assertOk();

    expect($r->json('data.firma_url'))->toContain('/firma/x.png');
    expect($r->json('data'))->toHaveKey('cargo');
});
