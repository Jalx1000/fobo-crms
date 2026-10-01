<?php

use Illuminate\Database\Migrations\Migration;
use Webkul\User\Models\Role;

/**
 * Crea el rol "Distribuidor" y le da el permiso del Punto.
 *
 * Rol propio y no un permiso más sobre Encargado: el distribuidor trabaja EN el
 * Punto. No necesita el tablero, ni la configuración, ni los pedidos de otras
 * ciudades, y dárselos es superficie de error sin ninguna ventaja.
 *
 * Los roles `permission_type = 'all'` (Administrador) ya entran sin tocar nada:
 * bouncer() les concede todo sin mirar la lista.
 */
return new class extends Migration
{
    const ROL = 'Distribuidor';

    const PERMISSION = 'distribuidor';

    /**
     * Lo mínimo para que el Punto funcione.
     *
     * `leads.*` y `contacts.persons.*` están porque el Punto crea pedidos y
     * contactos a través de los repositorios del CRM, y esos pasan por el ACL.
     * `whatsapp` está porque el Punto abre el chat del cliente.
     */
    const PERMISOS = [
        'distribuidor',
        'leads', 'leads.create', 'leads.view', 'leads.edit', 'leads.stage_update',
        'contacts', 'contacts.persons', 'contacts.persons.create', 'contacts.persons.view', 'contacts.persons.edit',
        'products', 'products.view',
        'whatsapp',
    ];

    public function up(): void
    {
        $rol = Role::where('name', self::ROL)->first();

        if (! $rol) {
            Role::create([
                'name'            => self::ROL,
                'description'     => 'Trabaja en el Punto de Distribuidor: toma pedidos, carga contactos y cobra.',
                'permission_type' => 'custom',
                'permissions'     => self::PERMISOS,
            ]);

            return;
        }

        // El rol ya existe (una corrida previa, o lo creó alguien a mano): se le
        // suman los permisos que falten sin pisar los que ya tenga.
        if ($rol->permission_type === 'all') {
            return;
        }

        $rol->permissions = array_values(array_unique(array_merge(
            (array) $rol->permissions,
            self::PERMISOS,
        )));

        $rol->save();
    }

    /**
     * No borra el rol: puede tener usuarios asignados, y borrarlo los dejaría sin
     * rol. Sólo quita el permiso del Punto.
     */
    public function down(): void
    {
        Role::where('permission_type', '!=', 'all')->each(function ($rol) {
            $permisos = (array) $rol->permissions;

            if (! in_array(self::PERMISSION, $permisos, true)) {
                return;
            }

            $rol->permissions = array_values(array_filter(
                $permisos,
                fn ($p) => $p !== self::PERMISSION,
            ));

            $rol->save();
        });
    }
};
