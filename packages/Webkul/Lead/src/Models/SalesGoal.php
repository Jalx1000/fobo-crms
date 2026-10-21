<?php

namespace Webkul\Lead\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Lead\Contracts\SalesGoal as SalesGoalContract;
use Webkul\User\Models\UserProxy;

/**
 * Una meta de venta para un período.
 *
 * `user_id` nulo es la meta del equipo. El avance NO se guarda acá: se calcula
 * contra los leads cada vez que se pide. Guardarlo crearía dos fuentes de
 * verdad que se desincronizan en el primer cambio de etapa retroactivo —y los
 * cambios retroactivos son la norma, no la excepción: un cierre se carga el
 * lunes con fecha del viernes.
 */
class SalesGoal extends Model implements SalesGoalContract
{
    protected $table = 'sales_goals';

    protected $fillable = [
        'user_id',
        'periodo',
        'metrica',
        'objetivo',
        'moneda',
        'created_by',
    ];

    protected $casts = [
        'objetivo' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(UserProxy::modelClass(), 'user_id');
    }

    public function autor()
    {
        return $this->belongsTo(UserProxy::modelClass(), 'created_by');
    }

    /**
     * ¿Es la meta del equipo y no de una persona?
     */
    public function esDelEquipo(): bool
    {
        return $this->user_id === null;
    }
}
