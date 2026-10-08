<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Webkul\User\Models\User;

/**
 * Configuración → Usuarios: la firma se puede quitar.
 *
 * El modal manda todos los campos en cada guardado, así que `firma` ausente
 * sigue significando «no la cambiaron»; quitarla es explícito (`quitar_firma`).
 *
 * Contra la base del contenedor con transacciones y disco falso: nada real se
 * toca. Actúa como el administrador (id 1).
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Storage::fake('public');
    Http::fake();
    Mail::fake();

    Storage::disk('public')->put('admins/x/firma/vieja.png', 'PNG');

    $this->asesor = User::findOrFail(DB::table('users')->insertGetId([
        'name'            => 'Firma test '.uniqid(),
        'email'           => 'firma'.uniqid().'@test.local',
        'password'        => bcrypt('x'),
        'role_id'         => DB::table('roles')->value('id'),
        'status'          => 1,
        'view_permission' => 'global',
        'cargo'           => 'Ejecutivo',
        'firma'           => 'admins/x/firma/vieja.png',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]));

    $this->actingAs(User::findOrFail(1), 'user');
});

function guardarUsuario($asesor, array $extra = [])
{
    return test()->withHeaders(['Accept' => 'application/json'])
        ->put(route('admin.settings.users.update', $asesor->id), array_merge([
            'name'            => $asesor->name,
            'email'           => $asesor->email,
            'role_id'         => $asesor->role_id,
            'view_permission' => 'global',
            'cargo'           => 'Gerente comercial',
        ], $extra));
}

it('quita la firma y borra el archivo', function () {
    guardarUsuario($this->asesor, ['quitar_firma' => 1])->assertOk();

    expect($this->asesor->fresh()->firma)->toBeNull();

    Storage::disk('public')->assertMissing('admins/x/firma/vieja.png');
});

it('editar otro campo no borra la firma', function () {
    guardarUsuario($this->asesor)->assertOk();

    expect($this->asesor->fresh())
        ->firma->toBe('admins/x/firma/vieja.png')
        ->cargo->toBe('Gerente comercial');

    Storage::disk('public')->assertExists('admins/x/firma/vieja.png');
});

it('quitar_firma en 0 no la borra', function () {
    guardarUsuario($this->asesor, ['quitar_firma' => 0])->assertOk();

    expect($this->asesor->fresh()->firma)->toBe('admins/x/firma/vieja.png');
});

it('si además sube una nueva, gana la nueva', function () {
    guardarUsuario($this->asesor, [
        'quitar_firma' => 1,
        'firma'        => UploadedFile::fake()->image('nueva.png'),
    ])->assertOk();

    $firma = $this->asesor->fresh()->firma;

    expect($firma)->not->toBeNull()->not->toBe('admins/x/firma/vieja.png');

    Storage::disk('public')->assertExists($firma);
    Storage::disk('public')->assertMissing('admins/x/firma/vieja.png');
});
