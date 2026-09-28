<?php

namespace Webkul\Core\Contracts\Validations;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Decimal implements ValidationRule
{
    /**
     * Cuántos decimales admite, y por qué ese número.
     *
     * Las columnas de precio del CRM son `decimal(12,4)`: products.price,
     * leads.lead_value y cualquier atributo de tipo `price`.
     *
     * La regla admitía UN solo decimal. Eloquent devuelve "1.0000", el
     * formulario lo pinta así, y al guardar el mismo valor que acababa de leer
     * no pasaba su propia validación: editar un producto con precio era
     * imposible sin reescribir el campo a mano.
     *
     * Más de 4 se sigue rechazando a propósito: MySQL los redondearía en
     * silencio y el precio guardado dejaría de ser el que el usuario escribió.
     */
    protected const DECIMALES = 4;

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^\d*(\.\d{1,'.self::DECIMALES.'})?$/', (string) $value)) {
            $fail(trans('admin::app.validations.message.decimal', ['attribute' => $attribute]));
        }
    }
}
