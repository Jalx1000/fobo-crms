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
        $ciAttr = static::attribute('ci_paciente');
        $ci = $ciAttr ? $this->getCustomAttributeValue($ciAttr) : null;

        $fechaAttr = static::attribute('fecha_nacimiento');
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

    /**
     * Definición del atributo, buscada una vez por proceso y no una por
     * persona: en una colección (ej. el selector de paciente) eran dos
     * consultas por fila. Los ids de estos atributos no cambian.
     */
    protected static function attribute(string $code)
    {
        static $attributes = [];

        return $attributes[$code] ??= app(AttributeRepository::class)->findOneByField('code', $code);
    }
}
