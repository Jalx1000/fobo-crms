<?php

namespace Webkul\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quién puede elegir el asesor (`user_id`) de un pedido o de un cliente.
 *
 * Los administradores siempre; un encargado, sólo si tiene tildado "Puede asignar
 * asesor" en Configuración → Usuarios (hoy, Huascar). Cambiar el asesor de un cliente
 * se lleva todos sus pedidos abiertos y su conversación (Distribuidor\Services\
 * Reparto), así que un error acá le saca clientes a un distribuidor.
 *
 * Sólo controla lo que elige una persona desde el CRM. Lo que asigna el reparto
 * automático no pasa por acá.
 */
class AsignacionDeAsesor
{
    public const ROL_ENCARGADO = 'Encargado';

    public function puede(?int $userId = null): bool
    {
        $userId ??= auth()->guard('user')->id();

        if (! $userId) {
            return false;
        }

        $usuario = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.id', $userId)
            ->first(['users.puede_asignar', 'roles.permission_type']);

        return $usuario
            && ($usuario->permission_type === 'all' || (bool) $usuario->puede_asignar);
    }

    public function esRolEncargado(?int $roleId): bool
    {
        return $roleId !== null
            && DB::table('roles')->where('id', $roleId)->value('name') === self::ROL_ENCARGADO;
    }

    /**
     * Rechaza el request si quien no puede asignar intenta cambiar el asesor.
     *
     * Dejarlo como estaba siempre vale: los formularios mandan el asesor actual en
     * cada guardado. En un alta también vale no elegir ninguno (lo pone el reparto) o
     * elegirse a sí mismo (el distribuidor que carga su propio pedido).
     *
     * @param  int|null  $actual  El asesor actual, o null si es un alta.
     */
    public function validar(array $data, ?int $actual, bool $esAlta = false): void
    {
        if (! array_key_exists('user_id', $data) || $this->puede()) {
            return;
        }

        $nuevo = $data['user_id'] === null || $data['user_id'] === '' ? null : (int) $data['user_id'];

        if ($nuevo === $actual) {
            return;
        }

        if ($esAlta && ($nuevo === null || $nuevo === (int) auth()->guard('user')->id())) {
            return;
        }

        throw ValidationException::withMessages([
            'user_id' => trans('admin::app.settings.users.index.create.sin-permiso-asignar'),
        ]);
    }
}
