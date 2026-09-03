<?php

namespace Webkul\Admin\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                   => $this->id,
            'title'                => $this->title,
            'lead_value'           => $this->lead_value,

            /*
             * El importe con SU moneda, no con la de la instalación.
             *
             * `formatBasePrice()` asume una sola moneda por instalación y acá se
             * opera en dólares y en bolivianos sin conversión, así que ponerle
             * BOB a un lead cerrado en dólares no es un detalle de formato: es
             * una cifra equivocada.
             *
             * Los leads anteriores al campo caen en la moneda por defecto, que
             * es la que el sistema asume al crearlos.
             */
            'lead_value_moneda'    => $this->lead_value_moneda,
            'formatted_lead_value' => ($this->lead_value_moneda
                ?: config('inmobiliaria.inmuebles.moneda_por_defecto', 'USD'))
                .' '.number_format((float) $this->lead_value, 0, ',', '.'),
            'status'               => $this->status,
            'expected_close_date'  => $this->expected_close_date,
            'rotten_days'          => $this->rotten_days,
            'closed_at'            => $this->closed_at,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
            'person'               => new PersonResource($this->person),
            'user'                 => new UserResource($this->user),
            'type'                 => new TypeResource($this->type),
            'source'               => new SourceResource($this->source),
            'pipeline'             => new PipelineResource($this->pipeline),
            'stage'                => new StageResource($this->stage),
            'tags'                 => TagResource::collection($this->tags),
        ];
    }
}
