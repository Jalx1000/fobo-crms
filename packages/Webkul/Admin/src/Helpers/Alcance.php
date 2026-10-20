<?php

namespace Webkul\Admin\Helpers;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Qué datos puede ver el usuario actual: el total de la empresa o solo lo suyo.
 *
 * Es el ÚNICO lugar donde se decide. El tablero y el inbox de WhatsApp
 * preguntan acá; ninguno resuelve la regla por su cuenta. Si cada pantalla
 * decidiera sola, alcanza con que una se olvide para que un asesor vea la
 * cartera de otro.
 *
 * Por ROL y no por permiso de vista (spec docs/specs/tablero-por-asesor.md,
 * D1; mismo criterio que ponce_de_leon): los ejecutivos están en «individual»
 * para no pelearse por los contactos, y el día que un supervisor quede con
 * otro permiso igual tiene que ver el total.
 */
class Alcance
{
    /**
     * ¿Ve el total de la empresa? Administradores y supervisores (D1).
     *
     * Sin usuario logueado (consola, jobs, reparto, API del agente) se
     * responde que sí: esos caminos no le muestran nada a una persona, y
     * recortarles los datos rompería el reparto y los comandos programados.
     */
    public function veTotal(): bool
    {
        $usuario = $this->usuario();

        if (! $usuario) {
            return true;
        }

        return in_array($usuario->role?->name, (array) config('reporting.roles_con_total', []), true);
    }

    /**
     * ¿Es administrador? No tiene ninguna restricción en ningún módulo (D11).
     *
     * Toda regla nueva que limite lo que alguien ve o hace tiene que dejar
     * pasar primero a quien devuelva true acá.
     */
    public function sinRestriccion(): bool
    {
        $usuario = $this->usuario();

        if (! $usuario) {
            return true;
        }

        return in_array($usuario->role?->name, (array) config('reporting.roles_sin_restriccion', ['Administrador']), true);
    }

    /**
     * ¿Es encargado nacional? (Bulmaro, 20/10/2026, `reporting.encargados_nacionales`).
     * Ve solo lo suyo, pero puede pasárselo a otro Ejecutivo Comercial.
     */
    public function esEncargado(): bool
    {
        $email = mb_strtolower((string) $this->usuario()?->email);

        return $email !== '' && in_array($email, array_map('mb_strtolower', (array) config('reporting.encargados_nacionales', [])), true);
    }

    /**
     * ¿Puede cambiar el asesor de algo (pedido, cotización, contacto,
     * derivación)? Quien ve el total, o un encargado nacional.
     */
    public function puedeReasignar(): bool
    {
        return $this->veTotal() || $this->esEncargado();
    }

    /**
     * ¿Puede dejar a $userId como asesor? Quien ve el total, a cualquiera; el
     * encargado nacional, solo a un Ejecutivo Comercial activo (pedido del
     * usuario: «solo puede reasignar a otro ejecutivo comercial»).
     */
    public function asesorPermitido(mixed $userId): bool
    {
        if ($this->veTotal()) {
            return true;
        }

        return $this->esEncargado() && is_numeric($userId) && in_array((int) $userId, self::ejecutivosActivos(), true);
    }

    /**
     * Ids de los ejecutivos comerciales activos: a quienes puede reasignar un
     * encargado nacional.
     *
     * @return array<int, int>
     */
    public static function ejecutivosActivos(): array
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'Ejecutivo Comercial')
            ->where('users.status', 1)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * El id por el que hay que filtrar, o null si ve todo.
     */
    public function userId(): ?int
    {
        return $this->veTotal() ? null : (int) $this->usuario()->id;
    }

    /**
     * Limita una consulta a los pedidos del usuario, si no ve el total.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function leads(Builder $query, string $columna = 'leads.user_id'): Builder
    {
        $userId = $this->userId();

        return $userId === null ? $query : $query->where($columna, $userId);
    }

    protected function usuario()
    {
        return auth()->guard('user')->user();
    }
}
