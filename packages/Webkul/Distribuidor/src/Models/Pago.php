<?php

namespace Webkul\Distribuidor\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Distribuidor\Contracts\Pago as PagoContract;
use Webkul\Lead\Models\LeadProxy;
use Webkul\User\Models\UserProxy;

class Pago extends Model implements PagoContract
{
    protected $table = 'distribuidor_pagos';

    protected $fillable = [
        'lead_id',
        'user_id',
        'amount',
        'method',
        'note',
    ];

    protected $casts = [
        // Float y no string: el Punto suma cobros para mostrar el saldo, y una
        // concatenación de strings ahí daría un total absurdo sin fallar.
        'amount' => 'float',
    ];

    public function lead()
    {
        return $this->belongsTo(LeadProxy::modelClass());
    }

    public function user()
    {
        return $this->belongsTo(UserProxy::modelClass());
    }

    /**
     * La etiqueta de la forma de pago, para mostrar.
     */
    public function getMethodLabelAttribute(): string
    {
        return config('distribuidor.formas_de_pago.'.$this->method, $this->method);
    }
}
