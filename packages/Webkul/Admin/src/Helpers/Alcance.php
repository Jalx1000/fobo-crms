<?php

namespace Webkul\Admin\Helpers;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Qué datos puede ver el usuario actual: el total de la empresa o solo lo suyo.
 *
 * Es el ÚNICO lugar donde se decide. El tablero, la tarjeta «Qué necesita
 * atención ahora» y el inbox de WhatsApp preguntan acá; ninguno resuelve la
 * regla por su cuenta. Si cada pantalla decidiera sola, alcanza con que una se
 * olvide para que un asesor vea la cartera de otro.
 *
 * Spec: docs/specs/tablero-por-asesor.md.
 */
class Alcance
{
    /**
     * ¿Ve el total de la empresa? Administradores y supervisores (D1).
     *
     * Sin usuario logueado (consola, jobs) se responde que sí: esos caminos no
     * le muestran nada a una persona, y recortarles los datos rompería
     * reportes y comandos programados.
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
     * ¿Es administrador? No tiene ninguna restricción en ningún módulo.
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
     * El id por el que hay que filtrar, o null si ve todo.
     */
    public function userId(): ?int
    {
        return $this->veTotal() ? null : (int) $this->usuario()->id;
    }

    /**
     * Limita una consulta a los leads del usuario, si no ve el total.
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

    /**
     * ¿Ve las conversaciones de WhatsApp sin contacto ni lead? (D11)
     *
     * Todos los administradores, más los usuarios puntuales de la config.
     */
    public function veSinAsignar(): bool
    {
        $usuario = $this->usuario();

        if (! $usuario || $this->sinRestriccion()) {
            return true;
        }

        return in_array((int) $usuario->id, array_map('intval', (array) config('whatsapp.ven_sin_asignar_user_ids', [])), true);
    }

    protected function usuario()
    {
        return auth()->guard('user')->user();
    }
}
