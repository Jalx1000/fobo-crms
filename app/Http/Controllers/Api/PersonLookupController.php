<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Core\Services\PhoneNumber;

/**
 * "¿Quién es este número?" — la ficha completa del contacto, atributos
 * personalizados incluidos, en una sola llamada.
 *
 * Existe porque las dos alternativas fallan:
 *
 *  - GET /api/v1/contacts/persons/search?search=contact_numbers:76616013 hace un
 *    LIKE crudo sobre la columna JSON, así que el mismo cliente guardado como
 *    "+59176616013" no aparece; acá el número pasa por la normalización
 *    compartida (PersonRepository::findByPhone).
 *  - PersonResource no expone los atributos personalizados, que son justamente
 *    los que el agente necesita (Ciudad, Sucursal).
 *
 * Los valores se devuelven crudos *y* etiquetados: `valor` es lo que está en la
 * base (para un select, el id) y `etiqueta` lo que se le muestra a una persona.
 */
class PersonLookupController extends Controller
{
    public function __construct(
        protected PersonRepository $persons,
        protected AttributeRepository $attributes,
        protected AttributeValueRepository $attributeValues,
    ) {}

    public function byPhone(Request $request): JsonResponse
    {
        $phone = (string) $request->query('telefono', $request->query('phone', ''));

        if (strlen(PhoneNumber::digits($phone)) < 7) {
            return response()->json([
                'message' => 'El parámetro telefono es requerido y debe tener al menos 7 dígitos.',
            ], 400);
        }

        $person = $this->persons->findByPhone($phone);

        // Un número que todavía no está en el CRM es una respuesta normal, no un
        // error: el agente pregunta antes de saber si el cliente existe.
        return response()->json([
            'telefono' => PhoneNumber::normalize($phone),
            'persona'  => $person ? $this->presentar($person) : null,
        ]);
    }

    /**
     * La ficha del contacto tal como la consume el agente.
     */
    protected function presentar($person): array
    {
        $person->load(['organization', 'user', 'attribute_values']);

        return [
            'id'              => $person->id,
            'nombre'          => $person->name,
            'cargo'           => $person->job_title,
            'organizacion'    => $person->organization->name ?? null,
            'asesor'          => $person->user->name ?? null,
            'correos'         => collect($person->emails ?? [])->pluck('value')->filter()->values(),
            'telefonos'       => collect($person->contact_numbers ?? [])->pluck('value')->filter()->values(),
            'creado_en'       => optional($person->created_at)->toIso8601String(),
            'atributos'       => $this->atributosPersonalizados($person),
        ];
    }

    /**
     * Sólo los atributos definidos por el equipo (is_user_defined). Los "de
     * fábrica" — nombre, correos, teléfonos — ya van arriba con su propio
     * nombre, y repetirlos acá sólo confunde al que lee la respuesta.
     *
     * @return array<int, array{code: string, nombre: string, tipo: string, valor: mixed, etiqueta: ?string}>
     */
    protected function atributosPersonalizados($person): array
    {
        $definiciones = $this->attributes
            ->findWhere(['entity_type' => 'persons', 'is_user_defined' => 1])
            ->sortBy('sort_order');

        return $definiciones->map(function ($attribute) use ($person) {
            $valor = $person->getCustomAttributeValue($attribute);

            return [
                'code'     => $attribute->code,
                'nombre'   => $attribute->name,
                'tipo'     => $attribute->type,
                'valor'    => $valor,
                'etiqueta' => $valor === null || $valor === ''
                    ? null
                    : $this->attributeValues->getAttributeLabel($valor, $attribute),
            ];
        })->values()->all();
    }
}
