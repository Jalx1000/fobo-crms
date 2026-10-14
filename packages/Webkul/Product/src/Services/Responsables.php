<?php

namespace Webkul\Product\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductoResponsableProxy;
use Webkul\Product\Support\ListaPrecios;
use Webkul\User\Models\UserProxy;
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

            self::cotizacionesConElPedido((int) $conversation->lead_id);
        }

        return $asesor;
    }

    /**
     * Las cotizaciones de un pedido pasan al asesor del pedido.
     *
     * Cuando el reparto cambiaba de dueño un pedido, sus cotizaciones quedaban
     * con el anterior y el reporte por asesor daba distinto según se mirara
     * pedidos o cotizaciones (37 casos el 13/10).
     */
    public static function cotizacionesConElPedido(int $leadId): void
    {
        $userId = DB::table('leads')->where('id', $leadId)->value('user_id');

        if (! $userId) {
            return;
        }

        DB::table('quotes')
            ->whereIn('id', DB::table('lead_quotes')->where('lead_id', $leadId)->select('quote_id'))
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $userId))
            ->update(['user_id' => $userId]);
    }

    /**
     * Todo lo del pedido pasa a su asesor: cotizaciones, la conversación ya
     * asignada y el contacto (si es su pedido más reciente).
     *
     * El 14/10 había 40 conversaciones atendidas por alguien distinto del
     * dueño del pedido: el reparto movía el pedido y el chat seguía con el
     * anterior. Una conversación sin asignar no se toca: asignarla es abrir
     * una derivación, y eso lo decide el agente.
     */
    public static function todoConElPedido(int $leadId): void
    {
        $lead = DB::table('leads')->where('id', $leadId)->first(['id', 'user_id', 'person_id']);

        if (! $lead?->user_id) {
            return;
        }

        self::cotizacionesConElPedido($leadId);

        DB::table('whatsapp_conversations')
            ->where('lead_id', $leadId)
            ->whereNotNull('assigned_user_id')
            ->where('assigned_user_id', '!=', $lead->user_id)
            ->update(['assigned_user_id' => $lead->user_id, 'assigned_at' => now()]);

        if ($lead->person_id
            && (int) DB::table('leads')->where('person_id', $lead->person_id)->max('id') === (int) $leadId) {
            DB::table('persons')->where('id', $lead->person_id)->update(['user_id' => $lead->user_id]);
        }
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
     * Si alguien puede quedar como asesor asignado: activo, y en la matriz
     * «Atiende» o con un rol que vende (no Administrador/Supervisores/Sistema,
     * whatsapp.handoff.exclude_roles).
     *
     * Deja afuera a los administradores y al usuario del agente: no venden,
     * así que un lead a su nombre no lo atiende nadie. Un Supervisor que está
     * en la matriz (Mariano, Bulmaro, Eduardo) sí vende y sí cuenta.
     */
    public function esAsesor(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        $query = UserProxy::modelClass()::whereKey($userId)->where('status', 1);

        $roles = array_filter((array) config('whatsapp.handoff.exclude_roles', []));

        if ($roles && ! $this->atiende($userId)) {
            $query->whereHas('role', fn ($q) => $q->whereNotIn('name', $roles));
        }

        return $query->exists();
    }

    /**
     * El asesor para quien no dijo ciudad, o cuya ciudad no atiende nadie.
     *
     * Ya no es una persona fija (era Mariano hasta el 13/10): es el turno
     * entre los ejecutivos «sin ciudad» —los que cubren «Bolivia» en la
     * matriz—, menos los de `cotizacion.fuera_del_turno`. Si
     * `cotizacion.asesor_por_defecto` está configurado, manda ese.
     */
    public function asesorPorDefecto()
    {
        $email = trim((string) config('cotizacion.asesor_por_defecto'));

        if ($email !== '') {
            $user = UserProxy::modelClass()::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

            if ($user && $this->esAsesor($user->id)) {
                return $user;
            }
        }

        return $this->turnoSinCiudad();
    }

    /**
     * El que sigue entre los ejecutivos de cobertura nacional: el que hace más
     * tiempo no recibe nada en esas casillas. Marca su turno.
     */
    /**
     * Si la persona es un ejecutivo «sin ciudad»: tiene casillas de cobertura
     * nacional. A ellos les toca lo que llega sin ciudad, por turno.
     */
    public function esSinCiudad(?int $userId): bool
    {
        return $userId && ProductoResponsableProxy::modelClass()::where('user_id', $userId)->get()->contains->esNacional();
    }

    protected function turnoSinCiudad()
    {
        if (! config('cotizacion.turno_sin_ciudad', true)) {
            return null;
        }

        $fuera = array_map('strtolower', array_filter(array_map('trim', (array) config('cotizacion.fuera_del_turno', []))));

        return DB::transaction(function () use ($fuera) {
            $filas = ProductoResponsableProxy::modelClass()::query()
                ->whereHas('user', fn ($q) => $q->where('status', 1))
                ->with('user')
                ->get()
                ->filter(fn ($r) => $r->esNacional() && ! in_array(strtolower((string) $r->user->email), $fuera, true));

            if ($filas->isEmpty()) {
                return null;
            }

            $userId = $filas->groupBy('user_id')
                ->map(fn ($suyas) => optional($suyas->max('ultimo_asignado_at'))->timestamp ?? 0)
                ->sortBy(fn ($ultimo, $id) => [$ultimo, $id])
                ->keys()
                ->first();

            ProductoResponsableProxy::modelClass()::query()
                ->whereIn('id', $filas->where('user_id', $userId)->pluck('id'))
                ->update(['ultimo_asignado_at' => now()]);

            return $filas->firstWhere('user_id', $userId)->user;
        });
    }

    /**
     * El asesor de un cliente por su ciudad, sin producto todavía: quien la
     * atiende (cualquier producto), por turno. Sin ciudad, o sin nadie en esa
     * ciudad, el asesor por defecto.
     *
     * Con producto conocido manda asignar(): esto es para el alta y para el
     * handoff que llega sin producto.
     */
    public function porCiudad(?string $ciudad)
    {
        if ($ciudad && ! self::esNacional($ciudad) && $asesor = $this->turnoEn($ciudad)) {
            return $asesor;
        }

        return $this->asesorPorDefecto();
    }

    /**
     * El que sigue en una ciudad, mirando todos sus productos: el que hace
     * más tiempo no recibe nada. Marca el turno en sus casillas de esa ciudad.
     */
    protected function turnoEn(string $ciudad)
    {
        return DB::transaction(function () use ($ciudad) {
            $filas = ProductoResponsableProxy::modelClass()::query()
                ->whereHas('user', fn ($q) => $q->where('status', 1))
                ->with('user')
                ->get()
                ->filter(fn ($r) => self::mismaCiudad((string) $r->ciudad, $ciudad));

            /*
            | Sin producto conocido, solo quien atiende TODOS los productos en
            | esa ciudad (Mauricio en Cochabamba, Gabriela en La Paz). Fabio
            | atiende solo Hules en Santa Cruz: mandarle a cualquier cliente de
            | Santa Cruz le cargaba consultas de productos que no vende.
            */
            $productos = ProductoResponsableProxy::modelClass()::query()->distinct()->count('product_id');

            $filas = $filas->groupBy('user_id')
                ->filter(fn ($suyas) => $suyas->pluck('product_id')->unique()->count() >= $productos)
                ->flatten(1);

            if ($filas->isEmpty()) {
                return null;
            }

            // El turno de una persona es su asignación más reciente en la
            // ciudad; nunca asignado va primero, y a igualdad, el id.
            $userId = $filas->groupBy('user_id')
                ->map(fn ($suyas) => optional($suyas->max('ultimo_asignado_at'))->timestamp ?? 0)
                ->sortBy(fn ($ultimo, $id) => [$ultimo, $id])
                ->keys()
                ->first();

            ProductoResponsableProxy::modelClass()::query()
                ->whereIn('id', $filas->where('user_id', $userId)->pluck('id'))
                ->update(['ultimo_asignado_at' => now()]);

            return $filas->firstWhere('user_id', $userId)->user;
        });
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
