<?php

use Illuminate\Database\Migrations\Migration;
use Webkul\User\Models\Role;

return new class extends Migration
{
    /**
     * La clave del permiso nuevo que controla el cambio de etapa de un pedido.
     */
    const PERMISSION = 'leads.stage_update';

    /**
     * Hasta ahora el cambio de etapa no estaba cubierto por ningun permiso, asi que
     * cualquier rol podia mover un pedido. Le damos el permiso nuevo a los roles que
     * ya podian editar pedidos para que nadie pierda una capacidad que hoy usa.
     */
    public function up(): void
    {
        Role::where('permission_type', '!=', 'all')->each(function ($role) {
            $permissions = $role->permissions ?? [];

            if (
                ! in_array('leads.edit', $permissions)
                || in_array(self::PERMISSION, $permissions)
            ) {
                return;
            }

            $permissions[] = self::PERMISSION;

            $role->permissions = array_values($permissions);

            $role->save();
        });
    }

    /**
     * Quita el permiso de todos los roles custom.
     */
    public function down(): void
    {
        Role::where('permission_type', '!=', 'all')->each(function ($role) {
            $permissions = $role->permissions ?? [];

            if (! in_array(self::PERMISSION, $permissions)) {
                return;
            }

            $role->permissions = array_values(
                array_filter($permissions, fn ($permission) => $permission !== self::PERMISSION)
            );

            $role->save();
        });
    }
};
