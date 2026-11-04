<?php

namespace Webkul\Contact\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Buscar contactos por teléfono y por carnet de identidad (CI).
 *
 * El CI se guarda en el atributo `nit` del contacto (el agente lo carga como
 * «NIT o Carnet»), que vive en `attribute_values` y no en `persons`; por eso los
 * buscadores no lo veían. El teléfono está en el JSON `contact_numbers` con su
 * prefijo («+59170760650»), así que lo escrito con espacios o guiones no
 * coincidía: un término que parece número se reduce a sus dígitos.
 */
class BusquedaContacto
{
    /**
     * El término tal como hay que buscarlo: solo dígitos si parece un teléfono
     * o un CI («+591 707-60650» → «59170760650»), y si no, igual.
     */
    public static function normalizar(string $termino): string
    {
        $termino = trim($termino);

        if (preg_match('/^[\d\s+\-().]+$/', $termino) && strlen(preg_replace('/\D/', '', $termino)) >= 5) {
            return preg_replace('/\D/', '', $termino);
        }

        return $termino;
    }

    /**
     * Subconsulta con el CI/NIT del contacto cuyo id está en `$personIdColumn`.
     */
    public static function nitSql(string $personIdColumn): Expression
    {
        return DB::raw(static::nitSubconsulta($personIdColumn));
    }

    /**
     * Lo mismo, para el SELECT: `(…) as nit`.
     */
    public static function nitSelect(string $personIdColumn, string $alias = 'nit'): Expression
    {
        return DB::raw(static::nitSubconsulta($personIdColumn).' as '.$alias);
    }

    protected static function nitSubconsulta(string $personIdColumn): string
    {
        $p = DB::getTablePrefix();

        [$tabla, $columna] = array_pad(explode('.', $personIdColumn, 2), 2, null);
        $columnaPersona = $columna ? "`{$p}{$tabla}`.`{$columna}`" : "`{$tabla}`";

        // Alias con el prefijo de tabla: Laravel no lo agrega dentro de un raw.
        return "(SELECT {$p}av.text_value FROM `{$p}attribute_values` {$p}av"
            ." WHERE {$p}av.entity_type = 'persons' AND {$p}av.entity_id = {$columnaPersona}"
            ." AND {$p}av.attribute_id = (SELECT {$p}a.id FROM `{$p}attributes` {$p}a WHERE {$p}a.code = 'nit' AND {$p}a.entity_type = 'persons' LIMIT 1)"
            .' LIMIT 1)';
    }

    /**
     * Ids de los contactos cuyo nombre, teléfono, email o CI contiene el término.
     *
     * @return array<int, int>
     */
    public static function personIds(string $termino): array
    {
        $termino = static::normalizar($termino);

        if ($termino === '') {
            return [];
        }

        $like = '%'.$termino.'%';

        $porNit = DB::table('attribute_values')
            ->where('entity_type', 'persons')
            ->where('attribute_id', DB::table('attributes')->where('code', 'nit')->where('entity_type', 'persons')->value('id'))
            ->where('text_value', 'like', $like)
            ->pluck('entity_id');

        return DB::table('persons')
            ->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('contact_numbers', 'like', $like)
                ->orWhere('emails', 'like', $like))
            ->pluck('id')
            ->merge($porNit)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
