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
     * Etapas que cierran el pedido y graban closed_at. El code se compara sin el
     * guion inicial (el id 11 quedó grabado como `-no-atendido`).
     */
    public const CLOSING_STAGE_CODES = [
        'pedidos-entregados',
        'pedidos-cancelados',
        'cliente-sin-inters',
        'otros-servicios',
        'won',
        'lost',
    ];

    public const CONFIRMED_STAGE_CODES = [
        'cliente-confirmado',
        'pedidos-confirmado',
    ];

    /**
     * Las fechas de etapa se graban acá y no en LeadRepository::update: el mass
     * update del kanban, las actividades y el agente cambian la etapa con
     * $lead->update() directo, y esos pedidos quedaban sin fecha.
     *
     * Va en boot() y no en booted(): booted() es del trait LogsActivity y
     * declararlo acá apaga el historial de actividades.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $lead) {
            if ($lead->isDirty('lead_pipeline_stage_id')) {
                $lead->stampStageDates();
            }
        });
    }

    /**
     * closed_at: ahora al entrar a una etapa de cierre, salvo que venga elegida
     * a mano (el modal de Entregado/Cancelado); null si el pedido se reabre.
     * confirmed_at y first_stage_change_at se fijan una sola vez.
     */
    protected function stampStageDates(): void
    {
        $stage = StageProxy::modelClass()::find($this->lead_pipeline_stage_id);
        $code = ltrim(strtolower((string) $stage?->code), '-');

        $isClosing = in_array($code, self::CLOSING_STAGE_CODES, true);
        $isConfirmed = in_array($code, self::CONFIRMED_STAGE_CODES, true);

        if ($isClosing) {
            if (! ($this->isDirty('closed_at') && $this->closed_at)) {
                $this->closed_at = Carbon::now();
            }
        } else {
            $this->closed_at = null;
        }

        if ($isConfirmed && ! $this->confirmed_at) {
            $this->confirmed_at = Carbon::now();
        }

        if (($isClosing || $isConfirmed) && ! $this->first_stage_change_at) {
            $this->first_stage_change_at = Carbon::now();
        }
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
