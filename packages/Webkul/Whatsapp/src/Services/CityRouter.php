<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Models\Conversation;

/**
 * A qué ciudad pertenece una conversación, y qué asesor la atiende.
 *
 * En kohlberg la ciudad ES el pipeline: cada una tiene el suyo (Tarija, Santa
 * Cruz, La Paz…) y un único Encargado que la atiende. Por eso el handoff no
 * rutea al dueño del lead como en los otros tenants, sino a la ciudad.
 *
 * El detalle que obliga a la cascada: medido sobre las 26 conversaciones
 * reales, solo 2 tienen lead y solo 9 tienen la ciudad cargada en la ficha del
 * cliente. Rutear solo por lead dejaría 24 sin asesor.
 */
class CityRouter
{
    /**
     * El atributo `cliente_ciudad` de la persona apunta a un lead_pipeline.
     */
    protected const ATRIBUTO_CIUDAD_PERSONA = 'cliente_ciudad';

    /**
     * La ciudad de una conversación, en orden de confianza.
     *
     * @param  string|int|null  $ciudadDelAgente  Lo que el agente dice haber
     *                                            averiguado hablando con el cliente.
     * @return int|null id del lead_pipeline
     */
    public function resolve(Conversation $conversation, string|int|null $ciudadDelAgente = null): ?int
    {
        // 1. El pipeline del lead. Es la fuente más fuerte: alguien del equipo
        //    ya clasificó este pedido, y en este CRM el pipeline manda sobre la
        //    ficha del cliente.
        if ($conversation->lead_id) {
            $pipeline = DB::table('leads')->where('id', $conversation->lead_id)->value('lead_pipeline_id');

            if ($pipeline && $this->esCiudadValida((int) $pipeline)) {
                return (int) $pipeline;
            }
        }

        // 2. La ciudad de la ficha del cliente.
        if ($conversation->person_id && $ciudad = $this->ciudadDePersona($conversation->person_id)) {
            return $ciudad;
        }

        /*
         * 3. Lo que dice el agente.
         *
         * Va ÚLTIMO a propósito: un LLM no puede pisar un dato que el equipo ya
         * cargó. Pero va, porque para 17 de 26 conversaciones el CRM no sabe la
         * ciudad y el agente acaba de preguntársela al cliente — sin esto, esas
         * derivaciones no tendrían a quién ir.
         */
        return $ciudadDelAgente !== null ? $this->pipelinePorNombre($ciudadDelAgente) : null;
    }

    /**
     * El asesor que atiende esa ciudad, o null si no hay ninguno.
     *
     * Primero un mapa explícito de config, para poder fijar una asignación sin
     * depender de los datos; si no hay entrada, se deduce de quién es dueño de
     * los leads de esa ciudad, que es como está organizado el equipo hoy.
     */
    public function advisorFor(?int $cityId): ?int
    {
        if (! $cityId) {
            return null;
        }

        $mapa = (array) config('whatsapp.handoff.city_agents', []);

        if (! empty($mapa[$cityId]) && $this->esAsesorValido((int) $mapa[$cityId])) {
            return (int) $mapa[$cityId];
        }

        return $this->deducirPorLeads($cityId);
    }

    /**
     * Nombre legible de la ciudad, para el banner y el mail.
     */
    public function cityName(?int $cityId): ?string
    {
        return $cityId
            ? DB::table('lead_pipelines')->where('id', $cityId)->value('name')
            : null;
    }

    /**
     * Guarda la ciudad en la ficha del cliente cuando el CRM no la tenía.
     *
     * Sin esto el dato que el agente averiguó se usa una vez y se pierde: la
     * próxima conversación vuelve a no saber de dónde escribe el cliente.
     */
    public function rememberOnPerson(int $personId, int $cityId): void
    {
        $attribute = DB::table('attributes')
            ->where('code', self::ATRIBUTO_CIUDAD_PERSONA)
            ->where('entity_type', 'persons')
            ->value('id');

        if (! $attribute) {
            return;
        }

        $existe = DB::table('attribute_values')
            ->where('entity_type', 'persons')
            ->where('entity_id', $personId)
            ->where('attribute_id', $attribute)
            ->exists();

        // Nunca se pisa un valor cargado por una persona.
        if ($existe) {
            return;
        }

        // Sin timestamps: `attribute_values` no los tiene en este esquema, y
        // agregarlos al insert falla con "Unknown column 'created_at'".
        DB::table('attribute_values')->insert([
            'entity_id'     => $personId,
            'entity_type'   => 'persons',
            'attribute_id'  => $attribute,
            'integer_value' => $cityId,
        ]);
    }

    protected function ciudadDePersona(int $personId): ?int
    {
        $attribute = DB::table('attributes')
            ->where('code', self::ATRIBUTO_CIUDAD_PERSONA)
            ->where('entity_type', 'persons')
            ->value('id');

        if (! $attribute) {
            return null;
        }

        $valor = DB::table('attribute_values')
            ->where('entity_type', 'persons')
            ->where('entity_id', $personId)
            ->where('attribute_id', $attribute)
            ->value('integer_value');

        return $valor && $this->esCiudadValida((int) $valor) ? (int) $valor : null;
    }

    /**
     * Resuelve lo que mandó el agente: un id, o un nombre de ciudad.
     *
     * La comparación ignora acentos y mayúsculas porque el agente escribe lo
     * que dijo el cliente: "potosi", "Potosí" y "POTOSI" son la misma ciudad, y
     * hacer que el LLM acierte la tilde no es una batalla que se gane.
     */
    protected function pipelinePorNombre(string|int $ciudad): ?int
    {
        if (is_numeric($ciudad) && $this->esCiudadValida((int) $ciudad)) {
            return (int) $ciudad;
        }

        $buscado = $this->normalizar((string) $ciudad);

        if ($buscado === '') {
            return null;
        }

        foreach ($this->ciudades() as $id => $nombre) {
            // esCiudadValida además de comparar el nombre: "Sin ciudad" existe
            // como pipeline y coincidiría por texto, devolviendo una ciudad que
            // no es una ciudad.
            if ($this->normalizar($nombre) === $buscado && $this->esCiudadValida((int) $id)) {
                return (int) $id;
            }
        }

        return null;
    }

    protected function normalizar(string $texto): string
    {
        return Str::of($texto)->trim()->lower()->ascii()->replace('.', '')->squish()->value();
    }

    /**
     * @return array<int, string>
     */
    protected function ciudades(): array
    {
        static $cache = null;

        return $cache ??= DB::table('lead_pipelines')->pluck('name', 'id')->all();
    }

    protected function esCiudadValida(int $id): bool
    {
        // "Sin ciudad" existe como pipeline pero no es una ciudad: rutear ahí
        // sería mandar la conversación a nadie disfrazado de asignación.
        $excluidas = array_map(
            fn ($n) => $this->normalizar((string) $n),
            (array) config('whatsapp.handoff.exclude_cities', ['Sin ciudad']),
        );

        $nombre = $this->ciudades()[$id] ?? null;

        return $nombre !== null && ! in_array($this->normalizar($nombre), $excluidas, true);
    }

    /**
     * El Encargado activo que más leads tiene en esa ciudad.
     *
     * Hoy hay exactamente uno por ciudad, pero se ordena por cantidad para que
     * un lead suelto de otro asesor no cambie el ruteo.
     */
    protected function deducirPorLeads(int $cityId): ?int
    {
        $roles = array_filter((array) config('whatsapp.handoff.advisor_roles', ['Encargado']));

        return DB::table('leads')
            ->join('users', 'users.id', '=', 'leads.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('leads.lead_pipeline_id', $cityId)
            ->where('users.status', 1)
            ->whereIn('roles.name', $roles)
            ->groupBy('users.id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('users.id');
    }

    protected function esAsesorValido(int $userId): bool
    {
        return UserProxy::modelClass()::where('id', $userId)->where('status', 1)->exists();
    }
}
