<?php

namespace Webkul\Product\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductoResponsableProxy;
use Webkul\Product\Support\ListaPrecios;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Quién atiende una consulta: la matriz producto × ciudad, por turnos.
 *
 * Reglas, en orden:
 *
 *  1. El local va primero. Si en la ciudad del cliente hay asesores para ese
 *     producto, se reparte entre ellos; los de cobertura nacional («Bolivia»)
 *     solo entran cuando en esa ciudad no hay nadie.
 *  2. Round robin: de los candidatos, el siguiente es el que hace más tiempo
 *     no recibe una consulta (`ultimo_asignado_at` más viejo, nunca asignado
 *     primero). No hay contador ni puntero: el estado es la propia fila.
 *  3. Un cliente no cambia de asesor a cada pedido. Si el contacto ya tiene un
 *     dueño y ese dueño está entre los candidatos, se queda con él.
 *
 * Fuera de horario se asigna igual: la consulta espera en la bandeja del
 * asesor, que es mejor que esperar en la de nadie.
 */
class Responsables
{
    /**
     * Los responsables que podrían atender este producto en esta ciudad,
     * ordenados por turno (el primero es el que sigue).
     *
     * @return Collection<int, \Webkul\Product\Models\ProductoResponsable>
     */
    public function candidatos(Product $producto, ?string $ciudad): Collection
    {
        $todos = ProductoResponsableProxy::modelClass()::query()
            ->where('product_id', $producto->id)
            ->whereHas('user', fn ($q) => $q->where('status', 1))
            ->with('user')
            ->orderBy('ultimo_asignado_at')
            ->orderBy('id')
            ->get();

        $locales = $ciudad
            ? $todos->filter(fn ($r) => self::mismaCiudad($r->ciudad, $ciudad))
            : collect();

        return $locales->isNotEmpty() ? $locales->values() : $todos->filter->esNacional()->values();
    }

    /**
     * El que sigue en el turno. Marca el turno: llamarlo dos veces devuelve dos
     * personas distintas (si las hay).
     */
    public function siguiente(Product $producto, ?string $ciudad)
    {
        return DB::transaction(function () use ($producto, $ciudad) {
            $fila = $this->candidatos($producto, $ciudad)->first();

            if (! $fila) {
                return null;
            }

            // Se marca el turno de la persona en TODAS sus casillas del
            // producto, no solo en la que ganó: si Mariano cubre Bolivia y
            // acaba de recibir una consulta de Santa Cruz, no debería ser el
            // siguiente en La Paz también.
            ProductoResponsableProxy::modelClass()::query()
                ->where('product_id', $producto->id)
                ->where('user_id', $fila->user_id)
                ->update(['ultimo_asignado_at' => now()]);

            return $fila->user;
        });
    }

    /**
     * El asesor de una conversación para este producto y ciudad, y lo deja
     * escrito en el contacto y en el lead para que el handoff caiga en la
     * misma persona.
     *
     * Devuelve null cuando nadie atiende esa combinación: la cotización queda
     * con el dueño que tenía —o sin dueño— y visible como tal.
     */
    public function asignar(Conversation $conversation, Product $producto, ?string $ciudad)
    {
        // Sin ciudad en el pedido, la del contacto: la escribieron una vez —el
        // agente, un asesor, otra cotización— y no hay razón para volver a
        // preguntarla ni para caer en la cobertura nacional teniéndola.
        $ciudad = $ciudad ?: $this->ciudadDelContacto($conversation);

        $candidatos = $this->candidatos($producto, $ciudad);

        if ($candidatos->isEmpty()) {
            return null;
        }

        /*
        | Quién la tiene hoy: el asignado de la conversación, si no el dueño
        | del contacto. Se respeta SOLO si esa persona figura en la matriz
        | —o sea, si vende—: las conversaciones viejas quedaron asignadas al
        | usuario administrador por handoffs de prueba, y respetar eso dejaba
        | todas las cotizaciones a nombre suyo en vez del vendedor que toca.
        */
        $actual = $conversation->assigned_user_id ?: $conversation->person?->user_id;

        $suya = $actual && $this->atiende((int) $actual);

        $asesor = $suya && $candidatos->contains('user_id', (int) $actual)
            // Atiende este producto en esta ciudad: se queda con su cliente.
            ? $candidatos->firstWhere('user_id', (int) $actual)->user
            // Vende, pero no esto: lo atiende quien corresponde. Si no vende,
            // tampoco: el turno decide.
            : $this->siguiente($producto, $ciudad);

        if (! $asesor) {
            return null;
        }

        /*
        | Se actualiza por consulta y no con `save()` sobre el modelo.
        |
        | `CustomAttribute::getAttribute()` cachea los atributos personalizados
        | dentro de `$model->attributes`, y Eloquent no los distingue de una
        | columna: cualquier `save()` posterior los mete en el UPDATE y MySQL
        | responde «Unknown column 'ciudad'». Leer la ciudad del contacto —lo
        | que hacemos arriba— alcanza para envenenar ese modelo.
        */
        if ($conversation->person_id) {
            $conversation->person()->getQuery()->whereKey($conversation->person_id)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $asesor->id))
                ->update(['user_id' => $asesor->id]);
        }

        if ($conversation->lead_id) {
            $conversation->lead()->getQuery()->whereKey($conversation->lead_id)
                ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $asesor->id))
                ->update(['user_id' => $asesor->id]);
        }

        return $asesor;
    }

    /**
     * Las ciudades válidas de la matriz: las de atención más la nacional.
     *
     * @return string[]
     */
    public static function ciudades(): array
    {
        return array_values(array_unique(array_map('strval', array_merge(
            (array) config('cotizacion.ciudades', []),
            [config('cotizacion.cobertura_nacional', 'Bolivia')]
        ))));
    }

    /**
     * Lo que atiende una persona: `[product_id => [ciudad, ...]]`, con todos
     * los productos presentes (vacíos incluidos) para que el formulario tenga
     * una clave por fila.
     */
    public function matrizDe(int $userId, iterable $productos): array
    {
        $matriz = [];

        foreach ($productos as $producto) {
            $matriz[$producto->id] = [];
        }

        $filas = ProductoResponsableProxy::modelClass()::where('user_id', $userId)->get();

        foreach ($filas as $fila) {
            $matriz[$fila->product_id][] = $fila->ciudad;
        }

        return $matriz;
    }

    /**
     * Deja lo que atiende una persona exactamente como vino del formulario:
     * `[product_id => [ciudad, ...]]`.
     *
     * Las casillas que siguen marcadas no se tocan, para no perder su
     * `ultimo_asignado_at`: si se borrara y reinsertara todo, cada guardado
     * volvería a poner a la persona «primera en el turno». Ciudades o
     * productos desconocidos se ignoran.
     */
    public function guardarDe(int $userId, array $matriz): void
    {
        $ciudades = self::ciudades();
        $productos = Product::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($userId, $matriz, $ciudades, $productos) {
            $modelo = ProductoResponsableProxy::modelClass();

            $deseadas = [];

            foreach ($matriz as $productId => $lista) {
                if (! in_array((int) $productId, $productos, true)) {
                    continue;
                }

                foreach ((array) $lista as $ciudad) {
                    if (in_array((string) $ciudad, $ciudades, true)) {
                        $deseadas[(int) $productId.'|'.$ciudad] = ['product_id' => (int) $productId, 'ciudad' => (string) $ciudad];
                    }
                }
            }

            foreach ($modelo::where('user_id', $userId)->get() as $fila) {
                $clave = $fila->product_id.'|'.$fila->ciudad;

                if (! isset($deseadas[$clave])) {
                    $fila->delete();
                }

                unset($deseadas[$clave]);
            }

            foreach ($deseadas as $nueva) {
                $modelo::create(['user_id' => $userId] + $nueva);
            }
        });
    }

    /**
     * La ciudad que tiene anotada el contacto de la conversación, por nombre.
     *
     * El atributo `ciudad` es un select: guarda el id de la opción, y lo que
     * la matriz compara son nombres.
     */
    public function ciudadDelContacto(Conversation $conversation): ?string
    {
        if (! $conversation->person_id) {
            return null;
        }

        $attribute = app(\Webkul\Attribute\Repositories\AttributeRepository::class)
            ->findWhere(['entity_type' => 'persons', 'code' => 'ciudad'])
            ->first();

        if (! $attribute) {
            return null;
        }

        // Se lee de la tabla y no de `$person->ciudad` a propósito: el accesor
        // de Krayin deja el atributo pegado al modelo y rompe el próximo save.
        $valor = DB::table('attribute_values')
            ->where('entity_type', 'persons')
            ->where('entity_id', $conversation->person_id)
            ->where('attribute_id', $attribute->id)
            ->value('integer_value');

        return $valor ? $attribute->options->firstWhere('id', (int) $valor)?->name : null;
    }

    /**
     * Si esta persona figura en la matriz. Quien está en la matriz atiende,
     * sin importar su rol: el handoff no lo puede mandar al pozo por ser
     * «Supervisor».
     */
    public function atiende(int $userId): bool
    {
        return ProductoResponsableProxy::modelClass()::where('user_id', $userId)->exists();
    }

    /**
     * Ciudad tal como se compara: sin acentos, sin espacios, en minúsculas.
     * «santa cruz», «Santa  Cruz» y «SANTA CRUZ» son la misma.
     */
    public static function mismaCiudad(string $a, string $b): bool
    {
        return ListaPrecios::comparable($a) === ListaPrecios::comparable($b);
    }

    public static function esNacional(string $ciudad): bool
    {
        return self::mismaCiudad($ciudad, (string) config('cotizacion.cobertura_nacional', 'Bolivia'));
    }
}
