<?php

use Illuminate\Database\Migrations\Migration;
use Webkul\User\Models\Role;

/**
 * Le da el permiso `whatsapp` a los roles que atienden el inbox.
 *
 * Los roles con `permission_type = 'all'` (Administrador) ya lo tienen por
 * definición: bouncer() les concede todo sin mirar la lista. Los `custom` no,
 * y ahí está el problema que esta migración arregla.
 *
 * Sin esto el módulo queda en un estado incoherente: `whatsapp.handoff
 * .advisor_roles` deriva conversaciones a `Encargado`, pero
 * `EnsureWhatsappAccess` le devuelve 403 a ese mismo rol. La derivación se
 * registra, sale el mail, y el asesor no puede abrir el chat.
 */
return new class extends Migration
{
    const PERMISSION = 'whatsapp';

    public function up(): void
    {
        Role::where('permission_type', '!=', 'all')->each(function ($role) {
            if (! $this->atiendeWhatsapp($role)) {
                return;
            }

            $permissions = $role->permissions ?? [];

            if (in_array(self::PERMISSION, $permissions, true)) {
                return;
            }

            $permissions[] = self::PERMISSION;

            $role->permissions = array_values($permissions);

            $role->save();
        });
    }

    /**
     * ¿Este rol custom debe poder abrir el inbox de WhatsApp?
     *
     * Se decide por `handoff.advisor_roles`, la MISMA config que elige a quién
     * se le derivan las conversaciones. Atarlos deja imposible la incoherencia
     * que motivó esta migración: derivarle a un rol al que el middleware le
     * responde 403, con la derivación registrada, el mail enviado, y el asesor
     * sin poder abrir el chat.
     *
     * Un rol nuevo NO entra solo, y eso es deliberado: el permiso da acceso a
     * leer toda la conversación de cualquier cliente y a escribir en nombre de
     * la empresa. Para sumarlo hay que agregarlo a `advisor_roles`, que es el
     * lugar donde alguien ya está pensando en quién atiende WhatsApp.
     */
    protected function atiendeWhatsapp(Role $role): bool
    {
        return in_array($role->name, (array) config('whatsapp.handoff.advisor_roles', []), true);
    }

    public function down(): void
    {
        Role::where('permission_type', '!=', 'all')->each(function ($role) {
            $permissions = $role->permissions ?? [];

            if (! in_array(self::PERMISSION, $permissions, true)) {
                return;
            }

            $role->permissions = array_values(
                array_filter($permissions, fn ($p) => $p !== self::PERMISSION)
            );

            $role->save();
        });
    }
};
