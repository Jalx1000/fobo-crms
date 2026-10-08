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
    /**
     * Avisa al asesor cuando el pedido PASA a "Cliente confirmado".
     *
     * El disparo va en el modelo y mira `wasChanged`, no el estado final, por
     * dos razones:
     *
     *   1. El listener anterior colgaba de `lead.update.after` y sólo
     *      preguntaba "¿está en confirmado?". Cada edición posterior de un
     *      pedido ya confirmado —cambiar el monto, agregar un producto, mover
     *      otra cosa— volvía a mandar el correo. El asesor recibía el mismo
     *      aviso varias veces por el mismo pedido.
     *   2. `updated` corre en el save exacto que cambió la etapa. Para cuando
     *      se dispara `lead.update.after`, CitySyncService y applyLeadValueRule
     *      ya volvieron a guardar el modelo y `wasChanged` quedó limpio.
     *
     * Cubre todos los caminos por igual: el formulario, arrastrar en el kanban
     * y el PUT de la API.
     */
    protected static function booted(): void
    {
        static::updated(function (self $lead) {
            if (! $lead->wasChanged('lead_pipeline_stage_id')) {
                return;
            }

            if (! core()->getConfigData('leads.notifications.confirmed_order.enabled')) {
                return;
            }

            /*
             * La etapa se lee de la base y no por $lead->stage: la relación
             * queda cacheada en la instancia, así que un pedido que ya había
             * cargado su etapa antes del cambio devolvía la VIEJA y el aviso no
             * se disparaba. Se notó en el caso de volver a confirmado después
             * de haber salido.
             */
            $code = \Illuminate\Support\Facades\DB::table('lead_pipeline_stages')
                ->where('id', $lead->lead_pipeline_stage_id)
                ->value('code');

            if ($code !== 'cliente-confirmado') {
                return;
            }

            // Nunca puede costar el guardado del pedido.
            try {
                \Webkul\Admin\Jobs\NotifyAdvisorConfirmedLead::dispatch($lead->id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('No se pudo encolar el aviso de pedido confirmado', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        });
    }

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
