<?php

namespace Webkul\Doctor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Webkul\Activity\Traits\LogsActivity;
use Webkul\Doctor\Contracts\Specialty as SpecialtyContract;

class Specialty extends Model implements SpecialtyContract
{
    use LogsActivity;

    protected $table = 'specialties';

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(DoctorProxy::modelClass(), 'doctor_specialty', 'specialty_id', 'doctor_id');
    }
}
