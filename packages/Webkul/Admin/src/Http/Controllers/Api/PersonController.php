<?php

namespace Webkul\Admin\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Contact\Models\Person;

/**
 * @OA\Tag(
 *     name="Persons",
 *     description="Endpoints para consultar pacientes con sus atributos personalizados"
 * )
 */
class PersonController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/persons",
     *     summary="Buscar paciente — por CI, nombre, email o wa_id/teléfono",
     *     description="Busca pacientes por distintos criterios. El parámetro `wa_id` busca en emails (format: wa_id@...) Y en contact_numbers en una sola llamada. Devuelve custom attributes completos. Requiere Bearer token.",
     *     tags={"Persons"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="wa_id", in="query", required=false,
     *         description="ID de WhatsApp / número de teléfono. Busca en emails (wa_id@*) Y en contact_numbers.",
     *         @OA\Schema(type="string", example="59175640698")
     *     ),
     *     @OA\Parameter(name="ci", in="query", required=false,
     *         description="Cédula exacta (custom attribute ci_paciente)",
     *         @OA\Schema(type="string", example="E-10131585")
     *     ),
     *     @OA\Parameter(name="name", in="query", required=false,
     *         description="Nombre parcial (LIKE)",
     *         @OA\Schema(type="string", example="García")
     *     ),
     *     @OA\Parameter(name="limit", in="query", required=false,
     *         @OA\Schema(type="integer", default=10, maximum=50)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Pacientes encontrados con custom attributes y wa_id detectado",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=42),
     *                 @OA\Property(property="name", type="string", example="María García"),
     *                 @OA\Property(property="wa_id", type="string", nullable=true, example="59175640698",
     *                     description="Extraído del email (wa_id@...) o del primer contact_number"),
     *                 @OA\Property(property="emails", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="contact_numbers", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="ci_paciente", type="string", nullable=true, example="E-10131585"),
     *                 @OA\Property(property="seguro_paciente", type="string", nullable=true, example="Membresía Odontoking"),
     *                 @OA\Property(property="carnet", type="string", nullable=true),
     *                 @OA\Property(property="fecha_nacimiento", type="string", nullable=true)
     *             )),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="total", type="integer"),
     *                 @OA\Property(property="limit", type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="No autenticado")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $limit    = min((int) $request->query('limit', 10), 50);
        $attrRepo = app(AttributeRepository::class);

        $attrs = collect(['ci_paciente', 'seguro_paciente', 'carnet', 'fecha_nacimiento'])
            ->mapWithKeys(fn ($code) => [$code => $attrRepo->findOneByField('code', $code)]);

        $query = Person::query();

        // wa_id: busca en emails (wa_id@*) OR en contact_numbers (valor exacto)
        if ($waId = trim((string) $request->query('wa_id', ''))) {
            $query->where(function ($q) use ($waId) {
                $q->whereRaw(
                    "JSON_SEARCH(emails, 'one', ?, NULL, '\$[*].value') IS NOT NULL",
                    [$waId . '@%']
                )->orWhereRaw(
                    "JSON_SEARCH(contact_numbers, 'one', ?, NULL, '\$[*].value') IS NOT NULL",
                    [$waId]
                );
            });
        }

        // ci: búsqueda exacta sobre attribute_values
        if ($ci = trim((string) $request->query('ci', ''))) {
            $ciAttr = $attrs['ci_paciente'];
            if ($ciAttr) {
                $matchingIds = DB::table('attribute_values')
                    ->where('attribute_id', $ciAttr->id)
                    ->where('entity_type', 'persons')
                    ->where('text_value', $ci)
                    ->pluck('entity_id');
                $query->whereIn('id', $matchingIds);
            }
        }

        if ($name = trim((string) $request->query('name', ''))) {
            $query->where('name', 'like', '%' . $name . '%');
        }

        $persons = $query->limit($limit)->get();

        // Bulk-load attribute_values en una sola query
        $personIds = $persons->pluck('id')->toArray();
        $attrIds   = $attrs->filter()->pluck('id')->values()->toArray();

        $avRows = empty($personIds) ? collect() : DB::table('attribute_values')
            ->whereIn('entity_id', $personIds)
            ->where('entity_type', 'persons')
            ->whereIn('attribute_id', $attrIds)
            ->get()
            ->groupBy('entity_id');

        $attrIdMap = $attrs->filter()->mapWithKeys(fn ($a, $code) => [$a->id => $code]);

        // Bulk-load seguro option labels
        $seguroAttr = $attrs['seguro_paciente'];
        $seguroLabels = $seguroAttr
            ? DB::table('attribute_options')
                ->where('attribute_id', $seguroAttr->id)
                ->pluck('name', 'id')
            : collect();

        $data = $persons->map(function (Person $p) use ($avRows, $attrIdMap, $attrs, $seguroLabels) {
            $row = [
                'id'              => $p->id,
                'name'            => $p->name,
                'wa_id'           => $this->extractWaId($p),
                'emails'          => $p->emails,
                'contact_numbers' => $p->contact_numbers,
            ];

            foreach ($attrs->keys() as $code) {
                $row[$code] = null;
            }

            foreach ($avRows->get($p->id, collect()) as $av) {
                $code = $attrIdMap[$av->attribute_id] ?? null;
                if ($code) {
                    $row[$code] = $av->text_value ?? $av->integer_value ?? $av->float_value ?? $av->boolean_value ?? $av->date_value ?? $av->datetime_value;
                }
            }

            // Resolver label de seguro_paciente
            if (is_numeric($row['seguro_paciente'])) {
                $row['seguro_paciente'] = $seguroLabels[(int) $row['seguro_paciente']] ?? $row['seguro_paciente'];
            }

            return $row;
        });

        return response()->json([
            'data' => $data,
            'meta' => ['total' => $data->count(), 'limit' => $limit],
        ]);
    }

    /**
     * Extrae el wa_id desde el email (wa_id@...) o del primer contact_number.
     * Prioriza el email whatsapp porque viene con el prefijo @whatsapp.*.
     */
    private function extractWaId(Person $p): ?string
    {
        foreach ($p->emails ?? [] as $entry) {
            $value = $entry['value'] ?? '';
            if (str_contains($value, '@whatsapp.')) {
                return explode('@', $value)[0];
            }
        }

        foreach ($p->contact_numbers ?? [] as $entry) {
            $value = trim($entry['value'] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
