<?php

namespace Webkul\Admin\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Webkul\Attribute\Repositories\AttributeRepository;

class PersonResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request)
    {
        $ciAttr = app(AttributeRepository::class)->findOneByField('code', 'ci_paciente');
        $ci = $ciAttr ? $this->getCustomAttributeValue($ciAttr) : null;

        $fechaAttr = app(AttributeRepository::class)->findOneByField('code', 'fecha_nacimiento');
        $fecha = $fechaAttr ? $this->getCustomAttributeValue($fechaAttr) : null;

        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'emails'           => $this->emails,
            'contact_numbers'  => $this->contact_numbers,
            'organization'     => new OrganizationResource($this->organization),
            'ci_paciente'      => $ci,
            'fecha_nacimiento' => $fecha,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
