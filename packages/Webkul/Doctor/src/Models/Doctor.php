<?php

namespace Webkul\Doctor\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Webkul\Activity\Models\ActivityProxy;
use Webkul\Activity\Traits\LogsActivity;
use Webkul\Attribute\Traits\CustomAttribute;
use Webkul\Doctor\Contracts\Doctor as DoctorContract;

class Doctor extends Model implements DoctorContract
{
    use HasFactory, LogsActivity, CustomAttribute;

    protected $table = 'doctors';

    protected $fillable = [
        'number',
        'name',
        'email',
        'phone',
        'title',
        'unique_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(SpecialtyProxy::modelClass(), 'doctor_specialty', 'doctor_id', 'specialty_id');
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(ActivityProxy::modelClass(), 'doctor_activities');
    }
}
