<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Webkul\Attribute\Repositories\AttributeRepository;

/**
 * Los atributos personalizados de una entidad, para el agente de IA.
 *
 * Existe porque los recursos de `krayin/rest-api` devuelven una lista FIJA de
 * campos y ningún atributo personalizado: se puede mandar `nit` en un POST y el
 * dato se guarda bien, pero la respuesta lo omite. Quien integra ve `null` y
 * concluye que falló.
 *
 * No se parchea el recurso porque vive en `vendor/`. Este endpoint es de solo
 * lectura y no duplica los de Krayin: los complementa devolviendo lo único que
 * a ellos les falta.
 *
 * Es genérico a propósito. Hoy hace falta para `nit`, pero la limitación es de
 * todos los atributos personalizados: mañana va a hacer falta para `sucursal` o
 * `cliente_ciudad`, y un endpoint por campo sería un endpoint nuevo cada vez.
 */
class AtributosController extends Controller
{
    /**
     * Entidades permitidas y de dónde se leen.
     *
     * Lista blanca y no `$request->entity_type` libre: sin esto, cualquiera con
     * un token podría pedir los atributos de `users` —donde viven datos de los
     * asesores— pasando el nombre de la tabla.
     */
    protected const ENTIDADES = [
        'persons'       => \Webkul\Contact\Repositories\PersonRepository::class,
        'organizations' => \Webkul\Contact\Repositories\OrganizationRepository::class,
        'leads'         => \Webkul\Lead\Repositories\LeadRepository::class,
        'quotes'        => \Webkul\Quote\Repositories\QuoteRepository::class,
        'products'      => \Webkul\Product\Repositories\ProductRepository::class,
    ];

    public function __construct(protected AttributeRepository $attributes) {}

    public function show(string $entidad, int $id): JsonResponse
    {
        if (! isset(self::ENTIDADES[$entidad])) {
            return response()->json([
                'message' => "No se pueden consultar atributos de «{$entidad}». Válidos: "
                    .implode(', ', array_keys(self::ENTIDADES)).'.',
            ], 404);
        }

        $modelo = app(self::ENTIDADES[$entidad])->find($id);

        if (! $modelo) {
            return response()->json(['message' => "No existe {$entidad} con id {$id}."], 404);
        }

        $atributos = [];

        foreach ($this->attributes->findWhere(['entity_type' => $entidad]) as $attribute) {
            $atributos[$attribute->code] = $modelo->{$attribute->code};
        }

        return response()->json([
            'entity_type' => $entidad,
            'id'          => $modelo->id,
            /*
            | (object) para que sea SIEMPRE un objeto JSON: un array PHP vacío se
            | serializa como [] y uno con claves como {}, y quien integra tendría
            | que manejar dos tipos para el mismo campo.
            */
            'atributos' => (object) $atributos,
        ]);
    }
}
