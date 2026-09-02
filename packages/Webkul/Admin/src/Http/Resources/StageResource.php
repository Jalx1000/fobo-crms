<?php

namespace Webkul\Admin\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StageResource extends JsonResource
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
            'code'                 => $this->code,
            'name'                 => $this->name,
            'lead_value'           => $this->lead_value,
            'formatted_lead_value' => core()->formatBasePrice($this->lead_value),

            /*
             * Total por moneda, ya formateado. No se usa formatBasePrice porque
             * asume la moneda de la instalación, y acá justamente conviven dos.
             */
            'totales_por_moneda'   => collect($this->lead_values_por_moneda ?? [])
                ->map(fn ($total, $moneda) => [
                    'moneda' => $moneda,
                    'total'  => $total,
                    'texto'  => $moneda.' '.number_format($total, 0, ',', '.'),
                ])
                ->values()
                ->all(),
            'is_user_defined'      => $this->is_user_defined,
            'sort_order'           => $this->sort_order,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        ];
    }
}
