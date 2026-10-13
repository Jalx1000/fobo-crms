<?php

namespace Webkul\Distribuidor\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Distribuidor\Contracts\Entrega as EntregaContract;
use Webkul\Lead\Models\LeadProxy;
use Webkul\User\Models\UserProxy;

class Entrega extends Model implements EntregaContract
{
    protected $table = 'distribuidor_entregas';

    protected $fillable = [
        'lead_id',
        'user_id',
        'entregado_at',
        'foto_path',
        'note',
    ];

    protected $casts = [
        'entregado_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(LeadProxy::modelClass());
    }

    public function user()
    {
        return $this->belongsTo(UserProxy::modelClass());
    }
}
