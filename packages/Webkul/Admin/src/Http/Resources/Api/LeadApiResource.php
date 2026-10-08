<?php

namespace Webkul\Admin\Http\Resources\Api;

use Illuminate\Support\Facades\DB;
use Webkul\RestApi\Http\Resources\V1\Lead\LeadResource;

/**
 * El lead de la API REST (krayin/rest-api) con sus atributos personalizados.
 *
 * El LeadResource del paquete solo serializa columnas del núcleo, así que la
 * `ciudad` que el agente guarda no volvía nunca en el GET (handoff 2026-10-07).
 * Se agrega, en el mismo formato en que el agente la manda en el PUT:
 *
 *   "ciudad": 42, "ciudad_nombre": "Cochabamba",
 *   "custom_attributes": { "ciudad": { "value": 42, "label": "Cochabamba" } }
 *
 * Se lee de attribute_values y no con `$this->ciudad`: el accesor de Krayin
 * deja el atributo pegado al modelo y rompe el próximo save().
 */
class LeadApiResource extends LeadResource
{
    public function toArray($request)
    {
        $custom = $this->atributosPersonalizados();

        $extra = ['custom_attributes' => (object) $custom];

        foreach ($custom as $code => $valor) {
            $extra[$code] = $valor['value'];

            if (array_key_exists('label', $valor)) {
                $extra[$code.'_nombre'] = $valor['label'];
            }
        }

        return array_merge(parent::toArray($request), $extra);
    }

    /**
     * @return array<string, array{value: mixed, label?: ?string}>
     */
    protected function atributosPersonalizados(): array
    {
        $atributos = DB::table('attributes')
            ->where('entity_type', 'leads')
            ->where('is_user_defined', 1)
            ->get(['id', 'code', 'type']);

        $valores = DB::table('attribute_values')
            ->where('entity_type', 'leads')
            ->where('entity_id', $this->id)
            ->whereIn('attribute_id', $atributos->pluck('id'))
            ->get()
            ->keyBy('attribute_id');

        $resultado = [];

        foreach ($atributos as $atributo) {
            $fila = $valores->get($atributo->id);

            if (in_array($atributo->type, ['select', 'multiselect', 'checkbox', 'lookup'], true)) {
                $valor = $fila?->integer_value ?? $fila?->text_value;

                $resultado[$atributo->code] = [
                    'value' => $valor === null ? null : (is_numeric($valor) ? (int) $valor : $valor),
                    'label' => $valor === null ? null : DB::table('attribute_options')->where('id', (int) $valor)->value('name'),
                ];

                continue;
            }

            $resultado[$atributo->code] = [
                'value' => $fila
                    ? ($fila->text_value ?? $fila->integer_value ?? $fila->float_value ?? $fila->boolean_value ?? $fila->date_value ?? $fila->datetime_value ?? $fila->json_value)
                    : null,
            ];
        }

        return $resultado;
    }
}
