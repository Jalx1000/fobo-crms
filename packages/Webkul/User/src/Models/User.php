<?php

namespace Webkul\User\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Webkul\User\Contracts\User as UserContract;

class User extends Authenticatable implements UserContract
{
    use HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'cargo',
        'email',
        'image',
        'firma',
        'password',
        'api_token',
        'role_id',
        'status',
        'view_permission',
        'horario',
        'telefono',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'api_token',
        'remember_token',
    ];

    /**
     * Get image url for the product image.
     */
    public function image_url()
    {
        if (! $this->image) {
            return;
        }

        return Storage::url($this->image);
    }

    /**
     * Get image url for the product image.
     */
    public function getImageUrlAttribute()
    {
        return $this->image_url();
    }

    /**
     * URL pública de la firma, o null si el asesor no cargó ninguna.
     *
     * Mismo mecanismo que el avatar: la columna guarda la ruta en el disco
     * público y la URL se arma al leer, así mover el storage no rompe nada.
     */
    public function getFirmaUrlAttribute(): ?string
    {
        return $this->firma ? Storage::url($this->firma) : null;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $array = parent::toArray();

        $array['image_url'] = $this->image_url;
        $array['firma_url'] = $this->firma_url;

        return $array;
    }

    /**
     * Lo que atiende en la matriz producto × ciudad (round robin).
     */
    public function atiende()
    {
        return $this->hasMany(\Webkul\Product\Models\ProductoResponsableProxy::modelClass());
    }

    /**
     * Get the role that owns the user.
     */
    public function role()
    {
        return $this->belongsTo(RoleProxy::modelClass());
    }

    /**
     * The groups that belong to the user.
     */
    public function groups()
    {
        return $this->belongsToMany(GroupProxy::modelClass(), 'user_groups');
    }

    /**
     * Checks if user has permission to perform certain action.
     *
     * @param  string  $permission
     * @return bool
     */
    public function hasPermission($permission)
    {
        if ($this->role->permission_type == 'custom' && ! $this->role->permissions) {
            return false;
        }

        return in_array($permission, $this->role->permissions);
    }
}
