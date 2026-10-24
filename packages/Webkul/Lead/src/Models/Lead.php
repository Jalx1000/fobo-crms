<?php

namespace Webkul\Lead\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Activity\Traits\LogsActivity;
use Webkul\Attribute\Traits\CustomAttribute;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Doctor\Models\DoctorProxy;
use Webkul\Email\Models\EmailProxy;
use Webkul\Lead\Contracts\Lead as LeadContract;
use Webkul\Quote\Models\QuoteProxy;
use Webkul\Tag\Models\TagProxy;
use Webkul\User\Models\UserProxy;

class Lead extends Model implements LeadContract
{
    use CustomAttribute, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'description',
        'lead_value',
        'lead_value_is_manual',
        'status',
        'lost_reason',
        'expected_close_date',
        'closed_at',
        'confirmed_at',
        'first_stage_change_at',
        'user_id',
        'person_id',
        'lead_source_id',
        'lead_type_id',
        'lead_pipeline_id',
        'lead_pipeline_stage_id',
        'doctor_id',
    ];

    /**
     * Cast the attributes to their respective types.
     *
     * @var array
     */
    protected $casts = [
        'closed_at'             => 'datetime:D M d, Y H:i A',
        'confirmed_at'          => 'datetime:D M d, Y H:i A',
        'first_stage_change_at' => 'datetime:D M d, Y H:i A',
        'detenido_at'           => 'datetime:D M d, Y H:i A',
        'expected_close_date'   => 'date:D M d, Y',
        'lead_value_is_manual'  => 'boolean',
    ];

    /**
     * The attributes that are appended.
     *
     * @var array
     */
    protected $appends = [
        'rotten_days',
    ];

    /**
     * Primera salida de «no atendido» (`reporting.stages.no_atendido`): de ahí
     * mide el tablero el «Tiempo en responder».
     *
     * Va en el modelo y no en el repositorio porque la etapa cambia por varios
     * caminos que no pasan por `LeadRepository::update` (edición masiva, la
     * actividad que mueve el lead, el servicio de «Sin interés»). Se fija una
     * sola vez: volver a Prospectos no reinicia el reloj.
     */
    protected static function booted(): void
    {
        static::saving(function (self $lead) {
            if (! $lead->lead_pipeline_stage_id || ! $lead->isDirty('lead_pipeline_stage_id')) {
                return;
            }

            $code = StageProxy::modelClass()::whereKey($lead->lead_pipeline_stage_id)->value('code');

            if ($code === null) {
                return;
            }

            if (! $lead->first_stage_change_at && ! in_array($code, (array) config('reporting.stages.no_atendido', []), true)) {
                $lead->first_stage_change_at = Carbon::now();
            }

            /*
            | Reloj del asesor: se detiene al entrar a una etapa de
            | `reporting.stages.detienen_tiempo` y vuelve a correr si el lead
            | regresa a una etapa que corre. Pasar entre dos etapas que detienen
            | (Sin interés → Desistimiento) conserva la primera fecha.
            */
            if (! static::tieneDetenidoAt()) {
                return;
            }

            if (in_array($code, (array) config('reporting.stages.detienen_tiempo', []), true)) {
                $lead->detenido_at ??= Carbon::now();
            } else {
                $lead->detenido_at = null;
            }
        });
    }

    /**
     * La columna llega con una migración; hasta que corra, el modelo no la
     * escribe (el código se sirve en vivo y guardar un lead no puede fallar).
     */
    protected static function tieneDetenidoAt(): bool
    {
        // Solo se recuerda el «sí»: un worker de cola que arrancó antes de la
        // migración tiene que enterarse cuando la columna aparezca.
        static $tiene = false;

        return $tiene = $tiene || \Illuminate\Support\Facades\Schema::hasColumn('leads', 'detenido_at');
    }

    /**
     * Get the user that owns the lead.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProxy::modelClass());
    }

    /**
     * Get the person that owns the lead.
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    /**
     * Get the type that owns the lead.
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TypeProxy::modelClass(), 'lead_type_id');
    }

    /**
     * Get the source that owns the lead.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SourceProxy::modelClass(), 'lead_source_id');
    }

    /**
     * Get the pipeline that owns the lead.
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(PipelineProxy::modelClass(), 'lead_pipeline_id');
    }

    /**
     * Get the pipeline stage that owns the lead.
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(StageProxy::modelClass(), 'lead_pipeline_stage_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProxy::modelClass(), 'doctor_id');
    }

    /**
     * Get the activities.
     */
    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(ActivityProxy::modelClass(), 'lead_activities');
    }

    /**
     * Get the products.
     */
    public function products(): HasMany
    {
        return $this->hasMany(ProductProxy::modelClass());
    }

    /**
     * Get the emails.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(EmailProxy::modelClass());
    }

    /**
     * The quotes that belong to the lead.
     */
    public function quotes(): BelongsToMany
    {
        return $this->belongsToMany(QuoteProxy::modelClass(), 'lead_quotes');
    }

    /**
     * The tags that belong to the lead.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TagProxy::modelClass(), 'lead_tags');
    }

    /**
     * Returns the rotten days
     */
    public function getRottenDaysAttribute()
    {
        if (! $this->stage) {
            return 0;
        }

        if (in_array($this->stage->code, ['won', 'lost'])) {
            return 0;
        }

        if (! $this->created_at) {
            return 0;
        }

        if (! $this->pipeline) {
            return 0;
        }

        $rottenDate = $this->created_at->addDays($this->pipeline->rotten_days);

        return $rottenDate->diffInDays(Carbon::now(), false);
    }
}
