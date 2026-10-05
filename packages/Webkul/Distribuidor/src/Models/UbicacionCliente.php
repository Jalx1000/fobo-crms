<?php

namespace Webkul\Distribuidor\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Distribuidor\Contracts\UbicacionCliente as UbicacionClienteContract;
use Webkul\User\Models\UserProxy;

/**
 * El domicilio georreferenciado del cliente. Una fila por cliente.
 */
class UbicacionCliente extends Model implements UbicacionClienteContract
{
    protected $table = 'distribuidor_ubicaciones';

    /** La tomada en la puerta con el GPS del celular. */
    public const FUENTE_GPS = 'gps';

    /** El pin que el cliente mandó por WhatsApp. */
    public const FUENTE_WHATSAPP = 'whatsapp';

    protected $fillable = [
        'person_id',
        'lat',
        'lng',
        'fuente',
        'user_id',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
    ];

    public function person()
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    public function user()
    {
        return $this->belongsTo(UserProxy::modelClass());
    }
}
