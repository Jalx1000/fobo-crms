<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Product\Support\ListaPrecios;

/**
 * La ciudad de un cliente, igual en su contacto, sus pedidos y sus cotizaciones.
 *
 * Cada entidad tiene su propio atributo `ciudad` (un select con sus propias
 * opciones), así que la ciudad viaja POR NOMBRE: el id 40 de `persons` y el 51
 * de `quotes` pueden ser la misma ciudad.
 *
 * La última que se cambia manda: se copia a las demás del mismo cliente.
 * (Antes solo se completaba la que estaba vacía y, si había dos distintas, no
 * se tocaba ninguna; quedaban distintas para siempre.)
 */
class CiudadCliente
{
    /**
     * Formas en que los clientes escriben una ciudad en el chat.
     */
    protected const ALIAS = [
        'cbba'                    => 'Cochabamba',
        'cocha'                   => 'Cochabamba',
        'sc'                      => 'Santa Cruz',
        'scz'                     => 'Santa Cruz',
        'santa cruz de la sierra' => 'Santa Cruz',
        'lp'                      => 'La Paz',
        'ea'                      => 'El Alto',
        'tja'                     => 'Tarija',
        // Beni y Pando van por departamento (13/10): la capital también vale.
        'trinidad'                => 'Beni',
        'trinidad beni'           => 'Beni',
        'cobija'                  => 'Pando',
        'cobija pando'            => 'Pando',
    ];

    public function __construct(
        protected AttributeRepository $attributes,
        protected AttributeValueRepository $values,
    ) {}

    /**
     * La ciudad guardada en una entidad, por nombre; null si no tiene.
     */
    public function de(string $entidad, int $id): ?string
    {
        $atributo = $this->atributo($entidad);

        if (! $atributo) {
            return null;
        }

        $fila = DB::table('attribute_values')
            ->where('entity_type', $entidad)
            ->where('entity_id', $id)
            ->where('attribute_id', $atributo->id)
            ->first(['integer_value', 'text_value']);

        if (! $fila) {
            return null;
        }

        return $this->opcion($entidad, $fila->integer_value ?? $fila->text_value)?->name;
    }

    /**
     * Escribe la ciudad (por nombre) en una entidad. Si no es una de sus
     * opciones no se inventa: se deja como estaba.
     */
    public function escribir(string $entidad, int $id, string $ciudad): bool
    {
        $atributo = $this->atributo($entidad);
        $opcion = $this->opcion($entidad, $ciudad);

        if (! $atributo || ! $opcion) {
            return false;
        }

        // La lista acotada a este atributo: sin ella save() reescribiría los
        // demás atributos de la entidad con lo que viene en $data (nada).
        $this->values->save([
            'entity_type' => $entidad,
            'entity_id'   => $id,
            'ciudad'      => $opcion->id,
        ], collect([$atributo]));

        return true;
    }

    /**
     * Después de guardar un contacto, pedido o cotización: su ciudad pasa a
     * todo lo del mismo cliente. Si no tiene, toma la del contacto.
     */
    public function sincronizarDesde(string $entidad, int $id): void
    {
        $personId = $entidad === 'persons'
            ? $id
            : (int) DB::table($entidad)->where('id', $id)->value('person_id');

        if (! $personId) {
            return;
        }

        $ciudad = $this->de($entidad, $id) ?? $this->de('persons', $personId);

        if ($ciudad) {
            $this->propagar($personId, $ciudad);
        }
    }

    /**
     * La ciudad en el contacto y en todos sus pedidos y cotizaciones.
     */
    public function propagar(int $personId, string $ciudad): void
    {
        $destinos = collect([['persons', $personId]])
            ->concat(DB::table('leads')->where('person_id', $personId)->pluck('id')->map(fn ($id) => ['leads', (int) $id]))
            ->concat(DB::table('quotes')->where('person_id', $personId)->pluck('id')->map(fn ($id) => ['quotes', (int) $id]));

        foreach ($destinos as [$entidad, $id]) {
            if ($this->de($entidad, $id) !== $this->opcion($entidad, $ciudad)?->name) {
                $this->escribir($entidad, $id, $ciudad);
            }
        }
    }

    /**
     * La ciudad que nombra un texto del cliente («cbba», «de Santa Cruz»), como
     * opción del contacto; null si no es una ciudad conocida.
     */
    public function desdeTexto(string $texto): ?string
    {
        $limpio = mb_strtolower(trim($texto));
        $limpio = preg_replace('/[.,!¡?¿]+$/u', '', $limpio);
        $limpio = preg_replace('/^(soy de|somos de|desde|de|en)\s+/u', '', trim($limpio));

        $limpio = self::ALIAS[$limpio] ?? $limpio;

        return $this->opcion('persons', $limpio)?->name;
    }

    /**
     * La misma ciudad con otro nombre: Beni y Pando se guardan por
     * departamento, pero un pedido o el agente pueden traer la capital.
     */
    protected const EQUIVALENTES = [
        'trinidad' => 'Beni',
        'cobija'   => 'Pando',
    ];

    protected function opcion(string $entidad, mixed $valor)
    {
        $valor = trim((string) ($valor ?? ''));
        $atributo = $this->atributo($entidad);

        if ($valor === '' || ! $atributo) {
            return null;
        }

        $buscar = fn (string $nombre) => $atributo->options->first(
            fn ($o) => ListaPrecios::comparable((string) $o->name) === ListaPrecios::comparable($nombre)
        );

        return (ctype_digit($valor) ? $atributo->options->firstWhere('id', (int) $valor) : null)
            ?? $buscar($valor)
            ?? (($otro = self::EQUIVALENTES[ListaPrecios::comparable($valor)] ?? null) ? $buscar($otro) : null);
    }

    protected function atributo(string $entidad)
    {
        static $cache = [];

        return $cache[$entidad] ??= $this->attributes
            ->findWhere(['entity_type' => $entidad, 'code' => 'ciudad'])
            ->first()
            ?->load('options');
    }
}
