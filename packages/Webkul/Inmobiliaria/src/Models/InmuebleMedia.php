<?php

namespace Webkul\Inmobiliaria\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Inmobiliaria\Contracts\InmuebleMedia as InmuebleMediaContract;

class InmuebleMedia extends Model implements InmuebleMediaContract
{
    protected $table = 'inmueble_medias';

    protected $fillable = [
        'inmueble_id', 'tipo', 'path', 'url', 'caption', 'orden', 'es_portada',
        'provider_media_id', 'provider_expira_at',
    ];

    protected $casts = [
        'es_portada'         => 'boolean',
        'provider_expira_at' => 'datetime',
    ];

    public function inmueble(): BelongsTo
    {
        return $this->belongsTo(InmuebleProxy::modelClass(), 'inmueble_id');
    }

    /**
     * ¿Sirve el media_id cacheado, o hay que volver a subir el archivo?
     *
     * Se considera vencido con margen (`revalidar_antes` días) para no descubrir
     * el vencimiento justo en medio de una conversación.
     */
    public function mediaIdVigente(): bool
    {
        if (blank($this->provider_media_id) || $this->provider_expira_at === null) {
            return false;
        }

        $margen = (int) config('inmobiliaria.inmuebles.media.revalidar_antes', 3);

        return $this->provider_expira_at->isAfter(now()->addDays($margen));
    }
}
