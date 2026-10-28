<?php

namespace Webkul\Contact\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Contact\Contracts\PatientConsent as PatientConsentContract;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\UserProxy;

/**
 * Consentimiento informado firmado por la paciente. Por paciente (product_id
 * nulo) o por tratamiento (product_id del servicio).
 */
class PatientConsent extends Model implements PatientConsentContract
{
    protected $table = 'patient_consents';

    protected $fillable = [
        'person_id',
        'activity_id',
        'product_id',
        'document',
        'accepted',
        'signed_name',
        'signed_at',
        'file_path',
        'performed_by',
    ];

    protected $casts = [
        'accepted'  => 'boolean',
        'signed_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(PersonProxy::modelClass(), 'person_id');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ActivityProxy::modelClass(), 'activity_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(UserProxy::modelClass(), 'performed_by');
    }
}
