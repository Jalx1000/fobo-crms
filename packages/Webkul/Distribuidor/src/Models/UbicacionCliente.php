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

    /** Puesta a mano sobre el mapa, al cargar el contacto. */
    public const FUENTE_MAPA = 'mapa';

    /** El pin que el cliente mandó por WhatsApp. */
    public const FUENTE_WHATSAPP = 'whatsapp';

    /**
     * Las fuentes que puso una persona a propósito.
     *
     * El pin de WhatsApp no pisa ninguna de estas: lo manda el cliente desde donde
     * está parado —que puede ser el trabajo— mientras el GPS se toma en la puerta y
     * el punto del mapa lo eligió alguien mirando el domicilio.
     *
     * @return array<int, string>
     */
    public static function fuentesHumanas(): array
    {
        return [self::FUENTE_GPS, self::FUENTE_MAPA];
    }

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
