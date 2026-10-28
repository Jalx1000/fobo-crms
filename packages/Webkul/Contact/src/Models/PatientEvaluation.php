<?php

namespace Webkul\Contact\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Contact\Contracts\PatientEvaluation as PatientEvaluationContract;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\UserProxy;

/**
 * Ficha de evaluación previa de una paciente para un tratamiento/cita.
 * answers = respuestas del cuestionario (preguntas definidas por Tessa).
 */
class PatientEvaluation extends Model implements PatientEvaluationContract
{
    protected $table = 'patient_evaluations';

    protected $fillable = [
        'person_id',
        'activity_id',
        'product_id',
        'answers',
        'notes',
        'performed_by',
        'performed_at',
    ];

    protected $casts = [
        'answers'      => 'array',
        'performed_at' => 'datetime',
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
