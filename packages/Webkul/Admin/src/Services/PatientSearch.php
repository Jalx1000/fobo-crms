<?php

namespace Webkul\Admin\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Buscador de pacientes por nombre, teléfono o CI, el mismo en personas,
 * leads (tabla y kanban) y calendario.
 *
 *  - Nombre: cada palabra tiene que estar, en cualquier orden ("javier mogro"
 *    encuentra a "Javier Alejandro Mogro").
 *  - Teléfono: se comparan solo los dígitos y sin el código de país, así
 *    "+591 7661 6013", "59176616013" y "76616013" encuentran lo mismo, esté
 *    guardado como esté.
 *  - CI: el atributo `ci_paciente`, también por sus dígitos ("1234567 LP").
 */
class PatientSearch
{
    /**
     * Limita `$query` a las filas cuyo paciente (`$personIdColumn`) coincide
     * con el término, o donde alguna de `$alsoLike` lo contiene (ej. el título
     * del lead o de la cita).
     */
    public function apply(Builder|EloquentBuilder $query, string $term, string $personIdColumn, array $alsoLike = []): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $persons = $this->matchingPersons($term);

        $query->where(function ($q) use ($term, $personIdColumn, $alsoLike, $persons) {
            $q->whereIn($personIdColumn, $persons);

            foreach ($alsoLike as $column) {
                $q->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * Subconsulta con los ids de las personas que coinciden.
     */
    public function matchingPersons(string $term): Builder
    {
        $term = trim($term);
        $digits = preg_replace('/\D/', '', $term);
        $words = array_values(array_filter(preg_split('/\s+/', $term), fn ($w) => $w !== ''));

        return DB::table('persons')->select('persons.id')->where(function ($q) use ($words, $digits) {
            // Nombre: todas las palabras.
            $q->where(function ($name) use ($words) {
                foreach ($words as $word) {
                    $name->where('persons.name', 'like', '%'.$word.'%');
                }
            });

            // Teléfono y CI: con al menos 4 dígitos (menos traería de todo).
            if (strlen($digits) >= 4) {
                $local = $this->localNumber($digits);

                $q->orWhere('persons.contact_numbers', 'like', '%'.$local.'%');

                if ($ciAttribute = $this->ciAttributeId()) {
                    $q->orWhereIn('persons.id', DB::table('attribute_values')
                        ->select('entity_id')
                        ->where('attribute_id', $ciAttribute)
                        ->where('entity_type', 'persons')
                        ->where('text_value', 'like', '%'.$digits.'%'));
                }
            }
        });
    }

    /**
     * Sin el código de país de Bolivia: los números están guardados con y sin él.
     */
    protected function localNumber(string $digits): string
    {
        $countryCode = (string) config('whatsapp.default_country_code', '591');

        return strlen($digits) > 8 && str_starts_with($digits, $countryCode)
            ? substr($digits, strlen($countryCode))
            : $digits;
    }

    protected function ciAttributeId(): ?int
    {
        $id = DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id');

        return $id ? (int) $id : null;
    }
}
