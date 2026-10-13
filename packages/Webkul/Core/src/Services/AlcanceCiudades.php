<?php

namespace Webkul\Core\Services;

use Illuminate\Support\Facades\DB;

/**
 * Qué ciudades (pipelines) puede ver cada usuario.
 *
 * En este CRM la ciudad ES el pipeline, y hay dos módulos que necesitan la misma
 * respuesta: el Punto (qué pedidos y sucursales ve el distribuidor) y la bandeja
 * de WhatsApp (qué conversaciones ve el asesor). Antes cada uno la resolvía a su
 * manera —el Punto con una tabla, WhatsApp con el mapa `city_agents` del config,
 * y ese mapa sólo servía para DERIVAR, no para VER—, así que un Encargado de
 * Jujuy veía las conversaciones de todas las ciudades.
 *
 * Vive en Core y no en uno de los dos módulos para que ninguno dependa del otro.
 *
 * La ciudad del usuario sale de su GRUPO de Krayin (los grupos se crean aparte,
 * en Settings → Grupos, y en esta instalación están nombrados como las ciudades),
 * y la tabla `user_ciudades` queda como override para los casos que el grupo no
 * cubre. El detalle de la cascada está en resolver().
 *
 * Quien ve todo el CRM —view_permission = global— sigue viendo todas las ciudades:
 * no tiene sentido que la bandeja restrinja a quien el ACL no restringe.
 *
 * No hay excepción para el administrador. Una fila vale para todos, porque un
 * "salvo los admins" escondido hace que la tabla mienta: alguien la carga, mira
 * la bandeja y sigue viendo todo sin entender por qué. Si un admin se restringe
 * de más, se arregla borrando su fila.
 */
class AlcanceCiudades
{
    /** @var array<int, array<int, int>|null> */
    protected array $memo = [];

    /**
     * Los ids de ciudad que el usuario puede ver, o null si puede ver todas.
     *
     * Null y no "todas las que existen hoy": así quien consulta distingue "sin
     * restricción" de "restringido a esta lista", y una ciudad nueva aparece sola
     * para los no restringidos en vez de quedar invisible hasta que alguien
     * actualice las filas.
     *
     * Array vacío cuando no hay sesión: sin usuario no se ve nada. Es lo opuesto
     * a null a propósito, para que un llamado sin autenticar no herede "todas".
     *
     * @return array<int, int>|null
     */
    public function ciudadesDe(?int $userId = null): ?array
    {
        $userId ??= auth()->guard('user')->id();

        if (! $userId) {
            return [];
        }

        // Se resuelve en varios pasos y se consulta en cada request (la lista y el
        // middleware la piden), así que el resultado se memoriza por request.
        if (array_key_exists($userId, $this->memo)) {
            return $this->memo[$userId];
        }

        return $this->memo[$userId] = $this->resolver($userId);
    }

    /**
     * La cascada que decide las ciudades del usuario.
     *
     * 1. Las filas de `user_ciudades`, si tiene. Es el override explícito: alguien
     *    las cargó a propósito y gana sobre cualquier deducción.
     * 2. `view_permission = global`: ve todo, y así lo dice el ACL de Krayin. No
     *    tiene sentido que la bandeja lo restrinja mientras el resto del CRM no.
     * 3. Sus GRUPOS de Krayin, puenteados a pipelines por nombre.
     *
     * El paso 3 es el que hace que esto funcione sin cargar nada: los grupos ya
     * existen y el negocio los mantiene (se crean aparte, en Settings → Grupos),
     * y están nombrados como las ciudades. Pedir que además se llene una segunda
     * tabla con lo mismo es la forma segura de que las dos se desincronicen.
     *
     * Si ninguno de sus grupos corresponde a una ciudad —un grupo "Ventas", por
     * ejemplo— vuelve null, o sea sin restringir. Un grupo que no habla de
     * ciudades no tiene nada que decir sobre ciudades, y dejarlo en "no ve
     * ninguna" sería dejar al usuario afuera sin que nadie lo haya pedido.
     *
     * @return array<int, int>|null
     */
    protected function resolver(int $userId): ?array
    {
        $explicitas = DB::table('user_ciudades')
            ->where('user_id', $userId)
            ->pluck('lead_pipeline_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($explicitas) {
            return $explicitas;
        }

        $vista = DB::table('users')->where('id', $userId)->value('view_permission');

        if ($vista === 'global') {
            return null;
        }

        return $this->ciudadesDeSusGrupos($userId) ?: null;
    }

    /**
     * Las ciudades que se deducen de los grupos del usuario.
     *
     * 🔴 El puente se hace por NOMBRE y no por id, y no es una comodidad: los ids
     * se contradicen. El grupo 1 es "San Salvador de Jujuy" y el pipeline 1 es
     * "Palpalá". Tratarlos como intercambiables le da al distribuidor la ciudad
     * equivocada sin producir ningún error.
     *
     * Los grupos son una entidad propia de Krayin, que se crea a mano en
     * Settings → Grupos: no salen de los pipelines ni de los depósitos. El nombre
     * es el único dato que las dos tablas comparten.
     *
     * @return array<int, int>
     */
    protected function ciudadesDeSusGrupos(int $userId): array
    {
        $nombres = DB::table('user_groups')
            ->join('groups', 'groups.id', '=', 'user_groups.group_id')
            ->where('user_groups.user_id', $userId)
            ->pluck('groups.name')
            ->map(fn ($n) => self::normalizar((string) $n))
            ->all();

        if (! $nombres) {
            return [];
        }

        return DB::table('lead_pipelines')
            ->get(['id', 'name'])
            ->filter(fn ($p) => in_array(self::normalizar((string) $p->name), $nombres, true))
            ->map(fn ($p) => (int) $p->id)
            ->values()
            ->all();
    }

    /**
     * Comparación tolerante a acentos, mayúsculas y espacios de más: las tablas
     * las cargaron personas distintas en momentos distintos, y "Palpalá" contra
     * "palpala" no puede ser una ciudad diferente.
     *
     * Vive acá y no en cada módulo para que haya UNA sola definición de "la misma
     * ciudad": el puente grupo→pipeline y el puente sucursal→pipeline tienen que
     * coincidir, o el pedido se abre en una ciudad y se cobra en otra.
     */
    public static function normalizar(string $valor): string
    {
        $sinAcentos = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $sinAcentos ?: $valor)));
    }

    /**
     * Si el usuario está restringido a algunas ciudades.
     *
     * Lo usan las vistas que cambian de forma según el caso: con una sola ciudad
     * visible no tiene sentido mostrar el selector de ciudades.
     */
    public function restringido(?int $userId = null): bool
    {
        return $this->ciudadesDe($userId) !== null;
    }

    /**
     * Si para este usuario la CIUDAD manda sobre el DUEÑO del lead.
     *
     * Krayin filtra los leads por dueño: con view_permission = group ves los de
     * los usuarios de tu grupo. Eso no sirve cuando el grupo representa una
     * ciudad, porque los pedidos los carga quien los atiende —el encargado, el
     * agente, otro distribuidor— y el distribuidor terminaba viendo sólo el único
     * que había cargado él. Medido: 1 lead de los 12 de su ciudad.
     *
     * Así que para quien tiene un grupo-ciudad, el filtro pasa a ser la ciudad y
     * deja de ser el dueño. Es lo que pidió el negocio: "de acuerdo a su grupo".
     *
     * Sólo con view_permission = group. Con `individual` NO, y es importante: ese
     * valor significa "sólo lo tuyo", y darle la ciudad entera le ampliaría el
     * acceso en vez de restringirlo. La ciudad igual le acota la bandeja de
     * WhatsApp, porque ahí sólo puede achicar.
     */
    public function ciudadMandaSobreDueno(?int $userId = null): bool
    {
        $userId ??= auth()->guard('user')->id();

        if (! $userId) {
            return false;
        }

        if (DB::table('users')->where('id', $userId)->value('view_permission') !== 'group') {
            return false;
        }

        return $this->ciudadesDe($userId) !== null;
    }

    public function puedeVer(int $pipelineId, ?int $userId = null): bool
    {
        $permitidas = $this->ciudadesDe($userId);

        return $permitidas === null || in_array($pipelineId, $permitidas, true);
    }

    /**
     * Las ciudades visibles, con nombre, para los selectores.
     *
     * Deja afuera las que el negocio no considera ciudades (hoy "Sin ciudad"),
     * porque como opción de un selector compite con "sin filtro" y significan lo
     * mismo para quien la lee.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function ciudades(?int $userId = null): array
    {
        $excluidas = (array) config('whatsapp.handoff.exclude_cities', []);
        $permitidas = $this->ciudadesDe($userId);

        return DB::table('lead_pipelines')
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->reject(fn ($p) => in_array($p->name, $excluidas, true))
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name])
            ->values()
            ->all();
    }
}
