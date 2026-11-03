<?php

namespace Webkul\Activity\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Oculta las actividades marcadas `admin_only` a quien no tenga un rol con
 * acceso total.
 *
 * Sin usuario autenticado (consola, colas, scripts de auditoría) no se
 * filtra nada: esos procesos tienen que ver todo el historial.
 */
class AdminOnlyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user || ($user->role?->permission_type ?? null) === 'all') {
            return;
        }

        $builder->where($model->getTable().'.admin_only', false);
    }
}
