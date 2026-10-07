<?php

namespace Webkul\Distribuidor\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Distribuidor\Contracts\Recordatorio as RecordatorioContract;

/**
 * El estado del recordatorio de recompra de un cliente.
 *
 * Ver la migración para por qué `activo` y `baja_at` son dos campos y no un estado
 * con tres valores.
 */
class Recordatorio extends Model implements RecordatorioContract
{
    protected $table = 'distribuidor_recordatorios';

    protected $fillable = [
        'person_id',
        'activo',
        'baja_at',
        'proximo_at',
        'intentos',
        'ultimo_envio_at',
        'ultima_respuesta',
        'ultima_respuesta_at',
        'cadencia_dias',
    ];

    protected $casts = [
        'activo'              => 'boolean',
        'baja_at'             => 'datetime',
        'proximo_at'          => 'datetime',
        'ultimo_envio_at'     => 'datetime',
        'ultima_respuesta_at' => 'datetime',
        'intentos'            => 'integer',
        'cadencia_dias'       => 'integer',
    ];

    public function person()
    {
        return $this->belongsTo(PersonProxy::modelClass());
    }

    /**
     * ¿Se le puede mandar un recordatorio a este cliente?
     *
     * La baja del CLIENTE pesa más que el interruptor del equipo: aunque alguien
     * reactive la fila desde el CRM, si el cliente pidió no recibir más, no se manda.
     * Para volver a mandarle hay que limpiar la baja explícitamente, que es lo que
     * hace `Recordatorios::reactivar()` cuando el cliente lo vuelve a pedir.
     */
    public function puedeRecibir(): bool
    {
        return $this->activo && ! $this->baja_at;
    }
}
