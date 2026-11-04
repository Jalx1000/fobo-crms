<?php

namespace App\Http\Controllers\Api\V1;

use Webkul\Admin\Http\Requests\AttributeForm;
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
 * Aquí se normalizan `contact_numbers` y `emails` a arrays (y se filtran las
 * entradas sin `value`) ANTES de delegar en el vendor, así el agente puede
 * actualizar solo los campos que cambian.
 */
class PersonController extends BasePersonController
{
    public function store(AttributeForm $request)
    {
        $this->normalizeContactArrays();

        return parent::store($request);
    }

    public function update(AttributeForm $request, $id)
    {
        $this->normalizeContactArrays();

        return parent::update($request, $id);
    }

    /**
     * Garantiza que `emails` y `contact_numbers` sean arrays de entradas con
     * `value`, para que el saneamiento del vendor no falle cuando no se envían.
     */
    private function normalizeContactArrays(): void
    {
        $request = request();

        foreach (['emails', 'contact_numbers'] as $field) {
            $value = $request->input($field);

            $value = is_array($value)
                ? array_values(array_filter(
                    $value,
                    fn ($entry) => is_array($entry) && ! is_null($entry['value'] ?? null) && $entry['value'] !== ''
                ))
                : [];

            $request->merge([$field => $value]);
        }
    }
}
