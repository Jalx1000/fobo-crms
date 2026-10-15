<?php

namespace Webkul\Contact\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Contact\Contracts\PersonInsuranceStatus as PersonInsuranceStatusContract;

/**
 * Estado de cobertura de UNA aseguradora de un paciente.
 *
 * Un paciente puede tener varias (el atributo `seguro_paciente` es multiselect),
 * y cada una se verifica contra su propio driver. Ver
 * `Webkul\Admin\Services\InsuranceService::verify()`.
 */
class PersonInsuranceStatus extends Model implements PersonInsuranceStatusContract
{
    protected $table = 'person_insurance_statuses';

    protected $fillable = [
        'person_id',
        'attribute_option_id',
        'seguro_name',
        'status',
        'badge',
        'message',
        'payload',
        'checked_at',
    ];

    protected $casts = [
        'payload'    => 'array',
        'checked_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(PersonProxy::modelClass(), 'person_id');
    }
}
