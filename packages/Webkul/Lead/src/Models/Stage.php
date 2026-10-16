<?php

namespace Webkul\Lead\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Lead\Contracts\Stage as StageContract;

class Stage extends Model implements StageContract
{
    public $timestamps = false;

    /**
     * Códigos de la primera etapa (el pedido todavía sin atender). El 16/10/2026
     * se corrigió en el CRM «NO ATENIDOS» → «NO ATENDIDOS» y el código pasó de
     * `no-atenidos` a `no-atendidos`: el pedido dejó de avanzar solo al recibir
     * una cotización. Se aceptan los dos para que renombrarla no lo rompa de nuevo.
     */
    public const NO_ATENDIDOS = ['no-atendidos', 'no-atenidos'];

    /**
     * Tono de cada etapa en el kanban (pedido del 16/10/2026), por CÓDIGO y
     * no por nombre: amarillo lo que sigue en curso, verde lo entregado, rojo
     * lo cerrado sin venta. Una etapa que no está acá queda neutra.
     */
    public const TONOS = [
        'en-curso' => [...self::NO_ATENDIDOS, 'solicitud-recibida', 'cotizacion-enviada'],
        'ganado'   => ['entregados', 'won'],
        'perdido'  => ['sin-inters', 'cancelados', 'otros-servicios', 'lost'],
    ];

    /**
     * 'en-curso', 'ganado', 'perdido' o null si la etapa no tiene tono.
     */
    public function tono(): ?string
    {
        foreach (self::TONOS as $tono => $codigos) {
            if (in_array($this->code, $codigos, true)) {
                return $tono;
            }
        }

        return null;
    }

    protected $table = 'lead_pipeline_stages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'code',
        'name',
        'probability',
        'sort_order',
        'lead_pipeline_id',
    ];

    /**
     * Get the pipeline that owns the pipeline stage.
     */
    public function pipeline()
    {
        return $this->belongsTo(PipelineProxy::modelClass(), 'lead_pipeline_id');
    }

    /**
     * Get the leads.
     */
    public function leads()
    {
        return $this->hasMany(LeadProxy::modelClass(), 'lead_pipeline_stage_id');
    }
}
