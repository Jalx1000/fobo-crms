<?php

namespace Webkul\Activity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Webkul\Activity\Contracts\Activity as ActivityContract;
use Webkul\Activity\Enums\AppointmentStatus;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Lead\Models\LeadProxy;
use Webkul\Product\Models\ProductProxy;
use Webkul\User\Models\UserProxy;
use Webkul\Warehouse\Models\WarehouseProxy;
use Webkul\Doctor\Models\DoctorProxy;

class Activity extends Model implements ActivityContract
{
    /**
     * Define table name of property
     *
     * @var string
     */
    protected $table = 'activities';

    /**
     * Define relationships that should be touched on save
     *
     * @var array
     */
    protected $with = ['user'];

    /**
     * Cast attributes to date time
     *
     * @var array
     */
    protected $casts = [
        'schedule_from' => 'datetime',
        'schedule_to'   => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'type',
        'location',
        'comment',
        'additional',
        'schedule_from',
        'schedule_to',
        'is_done',
        'user_id',
        'branch_id',
        'status',
        'rescheduled_to_activity_id',
    ];

    /**
     * Cache de si existe la columna status (un chequeo por request).
     */
    protected static ?bool $hasStatusColumn = null;

    /**
     * Mantiene el estado de la cita en sincronía con is_done:
     *  - meeting nuevo sin estado → scheduled
     *  - is_done = 1 → completed (salvo que ya esté cancelled / no_show / rescheduled)
     * Las transiciones explícitas (confirm/cancel/reschedule) las setea el
     * AppointmentStatusService; este observer solo cubre lo derivable de is_done.
     */
    protected static function booted(): void
    {
        static::saving(function (self $activity) {
            if ($activity->type !== 'meeting') {
                return;
            }

            if (static::$hasStatusColumn === null) {
                static::$hasStatusColumn = Schema::hasColumn('activities', 'status');
            }

            if (! static::$hasStatusColumn) {
                return;
            }

            $terminal = [
                AppointmentStatus::CANCELLED,
                AppointmentStatus::NO_SHOW,
                AppointmentStatus::RESCHEDULED,
            ];

            if (in_array($activity->status, $terminal, true)) {
                return;
            }

            if ($activity->is_done) {
                $activity->status = AppointmentStatus::COMPLETED;
            } elseif ($activity->status === null) {
                $activity->status = AppointmentStatus::SCHEDULED;
            }
        });
    }

    /**
     * Get the user that owns the activity.
     */
    public function user()
    {
        return $this->belongsTo(UserProxy::modelClass());
    }

    /**
     * The participants that belong to the activity.
     */
    public function participants()
    {
        return $this->hasMany(ParticipantProxy::modelClass());
    }

    /**
     * Get the file associated with the activity.
     */
    public function files()
    {
        return $this->hasMany(FileProxy::modelClass(), 'activity_id');
    }

    /**
     * The leads that belong to the activity.
     */
    public function leads()
    {
        return $this->belongsToMany(LeadProxy::modelClass(), 'lead_activities');
    }

    /**
     * The Person that belong to the activity.
     */
    public function persons()
    {
        return $this->belongsToMany(PersonProxy::modelClass(), 'person_activities');
    }

    /**
     * The leads that belong to the activity.
     */
    public function products()
    {
        return $this->belongsToMany(ProductProxy::modelClass(), 'product_activities');
    }

    /**
     * The Warehouse that belong to the activity.
     */
    public function warehouses()
    {
        return $this->belongsToMany(WarehouseProxy::modelClass(), 'warehouse_activities');
    }

    public function doctors()
    {
        return $this->belongsToMany(DoctorProxy::modelClass(), 'doctor_activities', 'activity_id', 'doctor_id');
    }
}
