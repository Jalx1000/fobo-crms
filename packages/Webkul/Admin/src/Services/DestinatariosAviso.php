<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Collection;
use Webkul\Product\Services\Responsables;
use Webkul\User\Models\UserProxy;

/**
 * A quién del equipo avisar de un registro nuevo (hoy: una cotización).
 *
 *   - el asesor dueño, si lo hay;
 *   - sin dueño y con ciudad → quienes la atienden (sección «Atiende» del
 *     usuario, cualquier producto);
 *   - sin ciudad, o si nadie atiende la ciudad → cobertura nacional
 *     («Bolivia»). El local va primero, igual que el round robin.
 *
 * Solo usuarios activos y con email, y nadie dos veces. Aparte, copias():
 * los correos fijos que reciben TODOS los avisos.
 */
class DestinatariosAviso
{
    public function equipo(?int $duenoId, ?string $ciudad): Collection
    {
        $equipo = collect();

        if ($duenoId) {
            $equipo = $this->activos()->where('id', $duenoId)->get();
        }

        if ($ciudad && $equipo->isEmpty()) {
            $equipo = $this->queAtienden($ciudad);
        }

        if ($equipo->isEmpty() || (! $ciudad && ! $duenoId)) {
            $equipo = $equipo->merge($this->queAtienden((string) config('cotizacion.cobertura_nacional', 'Bolivia')));
        }

        return $equipo->filter(fn ($user) => filled($user->email))->unique('id')->values();
    }

    /**
     * Los correos fijos de copia (separados por coma), sin los que ya
     * recibieron el aviso como asesores: nadie recibe dos.
     *
     * @return array<int, string>
     */
    public function copias(Collection $equipo, ?string $lista): array
    {
        $yaAvisados = $equipo->pluck('email')->map(fn ($email) => strtolower($email))->all();

        return collect(explode(',', (string) $lista))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->reject(fn ($email) => in_array($email, $yaAvisados, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Usuarios activos con al menos una fila de «Atiende» en esa ciudad. La
     * comparación es la de Responsables (sin tildes ni mayúsculas).
     */
    protected function queAtienden(string $ciudad): Collection
    {
        return $this->activos()
            ->whereHas('atiende')
            ->with('atiende:id,user_id,ciudad')
            ->get()
            ->filter(fn ($user) => $user->atiende->contains(
                fn ($fila) => Responsables::mismaCiudad((string) $fila->ciudad, $ciudad)
            ))
            ->values();
    }

    protected function activos()
    {
        return UserProxy::modelClass()::query()->where('status', 1);
    }
}
