<?php

namespace Webkul\Contact\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Contact\Contracts\Branch as BranchContract;

/**
 * Sucursal de Tessa (Santa Cruz, Asunción, ...). Cada una con su agenda,
 * horarios, base de pacientes, moneda y zona horaria.
 */
class Branch extends Model implements BranchContract
{
    protected $table = 'branches';

    protected $fillable = [
        'name',
        'slug',
        'city',
        'country',
        'currency',
        'timezone',
        'address',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
