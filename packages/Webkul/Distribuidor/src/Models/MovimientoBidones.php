<?php

namespace Webkul\Distribuidor\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Distribuidor\Contracts\MovimientoBidones as MovimientoBidonesContract;
use Webkul\Lead\Models\LeadProxy;
use Webkul\User\Models\UserProxy;

/**
 * Un movimiento de bidones: lo que se dejó y lo que se retiró en una visita.
 *
 * El saldo del cliente no vive acá: se calcula sumando sus movimientos. Ver
 * Services\Bidones.
 */
class MovimientoBidones extends Model implements MovimientoBidonesContract
{
    protected $table = 'distribuidor_bidones';

    protected $fillable = [
        'person_id',
        'lead_id',
        'user_id',
        'entregados',
        'retirados',
    ];

    protected $casts = [
        'entregados' => 'integer',
        'retirados'  => 'integer',
    ];

    public function person()
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    public function lead()
    {
        return $this->belongsTo(LeadProxy::modelClass());
    }

    public function user()
    {
        return $this->belongsTo(UserProxy::modelClass());
    }
}
