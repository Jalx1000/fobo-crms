<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Crea (idempotente) el rol "Sistema" y el usuario "Sin asignar".
     *
     * Estos respaldan la regla del tablero: los leads creados sin usuario en
     * contexto (sync SMD/Dropbox) deben quedar en una cuenta no-admin para que
     * los cards por-vendedor no los oculten. El rol "Sistema" es no-admin y
     * distinto de "Recepcionista" a propósito.
     */
    public function up(): void
    {
        $roleName = config('dashboard.unassigned_user.role', 'Sistema');

        $roleId = DB::table('roles')->where('name', $roleName)->value('id');

        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name'            => $roleName,
                'description'     => 'Rol de sistema (no-admin) para cuentas técnicas como "Sin asignar".',
                'permission_type' => 'custom',
                'permissions'     => json_encode([]),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        $email = config('dashboard.unassigned_user.email', 'sin-asignar@sistema.local');
        $name = config('dashboard.unassigned_user.name', 'Sin asignar');

        $exists = DB::table('users')->where('email', $email)->exists();

        if (! $exists) {
            DB::table('users')->insert([
                'name'       => $name,
                'email'      => $email,
                'password'   => null,
                'status'     => 1,
                'role_id'    => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * No elimina el usuario/rol: pueden tener leads reasignados apuntando a la
     * cuenta. Revertir borraría esa integridad referencial, así que es no-op.
     */
    public function down(): void
    {
        // Intencionalmente vacío (ver nota arriba).
    }
};
