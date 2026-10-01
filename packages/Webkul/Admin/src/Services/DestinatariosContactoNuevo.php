<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Collection;
use Webkul\Admin\Listeners\Ciudad;
use Webkul\Product\Services\Responsables;
use Webkul\User\Models\UserProxy;

/**
 * A quién avisar cuando se registra un contacto nuevo.
 *
 *   cliente  → la bienvenida, a sus emails (puede no tener ninguno).
 *   equipo   → el aviso interno:
 *     - el asesor asignado, si lo hay;
 *     - sin asesor y con ciudad → quienes la atienden (sección «Atiende» del
 *       usuario, cualquier producto);
 *     - sin ciudad, o si nadie atiende la ciudad → los de cobertura nacional
 *       («Bolivia»). El local va primero, igual que el round robin.
 *
 * Solo usuarios activos y con email, y nadie dos veces.
 *
 * Spec: docs/specs/correo-contacto-nuevo.md
 */
class DestinatariosContactoNuevo
{
    public function __construct(protected Ciudad $ciudades) {}

    /**
     * @return array{cliente: array<int, string>, equipo: Collection, ciudad: ?string}
     */
    public function para($person): array
    {
        $ciudad = $this->ciudades->nombreDe('persons', $person->ciudad ?? null);

        $equipo = collect();

        if ($person->user_id) {
            $equipo = $equipo->merge($this->activos()->where('id', $person->user_id)->get());
        }

        if ($ciudad && ! $person->user_id) {
            $equipo = $equipo->merge($this->queAtienden($ciudad));
        }

        // Sin ciudad siempre; con ciudad, solo si nadie la atiende.
        if (! $ciudad || (! $person->user_id && $equipo->isEmpty())) {
            $equipo = $equipo->merge($this->queAtienden((string) config('cotizacion.cobertura_nacional', 'Bolivia')));
        }

        return [
            'cliente' => collect($person->emails ?? [])
                ->pluck('value')
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->unique()
                ->values()
                ->all(),
            'equipo'  => $equipo->filter(fn ($user) => filled($user->email))->unique('id')->values(),
            'ciudad'  => $ciudad,
        ];
    }

    /**
     * Usuarios activos con al menos una fila de «Atiende» en esa ciudad.
     * La comparación es la de Responsables (sin tildes ni mayúsculas), para que
     * «Potosi» y «Potosí» sean la misma ciudad.
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
