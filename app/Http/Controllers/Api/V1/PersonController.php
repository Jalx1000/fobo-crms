<?php

namespace App\Http\Controllers\Api\V1;

use Webkul\Admin\Http\Requests\AttributeForm;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\RestApi\Http\Controllers\V1\Contact\Persons\PersonController as BasePersonController;

/**
 * Override del endpoint REST de pacientes.
 *
 * El `sanitizeRequestedPersonData()` del vendor hace
 * `collect($data['contact_numbers'])->filter(...)` sin verificar que la clave
 * exista: un PUT/POST que no manda `contact_numbers` (p. ej. renombrar con solo
 * `{"name": "..."}`) dispara un "Undefined array key", que en producción se
 * convierte en ErrorException → 500.
 *
 * Además, el vendor SIEMPRE escribe `emails`/`contact_numbers`, así que un PUT
 * parcial que no los envía los dejaría vacíos (borrando teléfonos y el wa_id con
 * que el agente resuelve al paciente). Aquí, en un update, los campos ausentes se
 * rellenan con los valores ACTUALES del paciente (preservar), no con [].
 */
class PersonController extends BasePersonController
{
    public function store(AttributeForm $request)
    {
        $this->normalizeContactArrays(null);

        return parent::store($request);
    }

    public function update(AttributeForm $request, $id)
    {
        $this->normalizeContactArrays($id);

        return parent::update($request, $id);
    }

    /**
     * Normaliza `emails` y `contact_numbers` a arrays de entradas con `value`.
     *
     * - Si el request TRAE el campo, se usa (filtrando entradas sin `value`):
     *   permite reemplazar o vaciar explícitamente.
     * - Si NO lo trae: en update se preservan los valores actuales del paciente;
     *   en create (sin $id) queda [].
     */
    private function normalizeContactArrays(?int $id): void
    {
        $request = request();

        $existing = $id ? app(PersonRepository::class)->find($id) : null;

        foreach (['emails', 'contact_numbers'] as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);

                $value = is_array($value)
                    ? array_values(array_filter(
                        $value,
                        fn ($entry) => is_array($entry) && ! is_null($entry['value'] ?? null) && $entry['value'] !== ''
                    ))
                    : [];
            } else {
                // Campo ausente: preservar lo que ya tiene el paciente (o [] al crear).
                $value = $existing ? (array) ($existing->{$field} ?? []) : [];
            }

            $request->merge([$field => $value]);
        }
    }
}
