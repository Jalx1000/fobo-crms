<?php

namespace Webkul\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién es distribuidor y de quién es cada cliente.
 *
 * Cada distribuidor es una EMPRESA distinta, aunque reparta en la misma ciudad que
 * otro. Si uno ve el pedido, el contacto o la conversación del otro, se pelean por
 * el cliente. Por eso, para un distribuidor, el criterio no es la ciudad sino el
 * dueño: ve sólo lo que está asignado a él.
 *
 * Un usuario es distribuidor si está cargado en `distribuidor_zonas`, no por el
 * nombre de su rol: la tabla es lo que dice en qué ciudades reparte. La tabla se
 * mantiene sola desde el rol y los grupos del usuario (Distribuidor\Services\
 * ZonasDelDistribuidor), así que registrarlo en Configuración → Usuarios alcanza.
 *
 * El dueño de un cliente es `persons.user_id`. Todos sus pedidos y su conversación
 * de WhatsApp siguen a ese dueño, así un mismo cliente nunca queda repartido entre
 * dos empresas.
 *
 * Vive en Core, igual que AlcanceCiudades, para que Admin, WhatsApp y el Punto lo
 * consulten sin depender del módulo Distribuidor. Sólo lee: quién recibe un
 * cliente nuevo lo decide Distribuidor\Services\Reparto.
 */
class Distribuidores
{
    /** @var array<int, bool> */
    protected array $esDistribuidor = [];

    /** @var array<int, array<int, int>> */
    protected array $empresa = [];

    protected function tablaLista(): bool
    {
        static $existe = null;

        return $existe ??= Schema::hasTable('distribuidor_zonas');
    }

    /**
     * Si el usuario reparte en alguna ciudad.
     *
     * Se consulta en cada request (la grilla, la bandeja y el middleware lo
     * preguntan), así que se memoriza por request.
     */
    public function esDistribuidor(?int $userId = null): bool
    {
        $userId ??= auth()->guard('user')->id();

        if (! $userId || ! $this->tablaLista()) {
            return false;
        }

        return $this->esDistribuidor[$userId] ??= DB::table('distribuidor_zonas')
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * El id del usuario en sesión si es distribuidor, o null si no lo es.
     *
     * Es la pregunta que hace cada consulta: "¿tengo que acotar a lo suyo?". Null
     * significa que manda la regla de siempre (ciudad o dueño según Krayin).
     */
    public function soloLoSuyo(): ?int
    {
        $userId = auth()->guard('user')->id();

        return $userId && $this->esDistribuidor((int) $userId) ? (int) $userId : null;
    }

    /**
     * De quiénes puede ver lo suyo el distribuidor en sesión, o null si no es uno.
     *
     * Su EMPRESA, según su permiso de vista:
     *   individual -> sólo él.
     *   grupo      -> él y los usuarios de sus grupos. El grupo de un distribuidor es
     *                 su empresa (nunca la ciudad: el formulario de usuario no deja).
     *
     * `soloLoSuyo()` dice quién es él (a nombre de quién se crea algo); esto dice qué
     * ve. Con permiso individual, que es el caso de hoy, son lo mismo.
     *
     * @return array<int, int>|null
     */
    public function miEmpresa(): ?array
    {
        if (! $yo = $this->soloLoSuyo()) {
            return null;
        }

        if (array_key_exists($yo, $this->empresa)) {
            return $this->empresa[$yo];
        }

        $companeros = DB::table('users')->where('id', $yo)->value('view_permission') === 'group'
            ? DB::table('user_groups as mio')
                ->join('user_groups as otro', 'otro.group_id', '=', 'mio.group_id')
                ->where('mio.user_id', $yo)
                ->pluck('otro.user_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        return $this->empresa[$yo] = array_values(array_unique([$yo, ...$companeros]));
    }

    /** Si ese usuario es de la empresa del distribuidor en sesión. */
    public function esDeMiEmpresa(?int $userId): bool
    {
        return $userId !== null && in_array($userId, $this->miEmpresa() ?? [], true);
    }

    /**
     * Los distribuidores activos de una ciudad, en orden estable.
     *
     * @return array<int, int>
     */
    public function deCiudad(?int $pipelineId): array
    {
        if (! $pipelineId || ! $this->tablaLista()) {
            return [];
        }

        return DB::table('distribuidor_zonas')
            ->join('users', 'users.id', '=', 'distribuidor_zonas.user_id')
            // Uno dado de baja no puede recibir clientes: quedarían a nombre de alguien
            // que ya no entra al CRM, y nadie los vería.
            ->where('users.status', 1)
            ->where('distribuidor_zonas.lead_pipeline_id', $pipelineId)
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function esDistribuidorDe(int $userId, ?int $pipelineId): bool
    {
        return in_array($userId, $this->deCiudad($pipelineId), true);
    }

    /**
     * El distribuidor dueño del cliente, o null si no tiene uno.
     *
     * Un cliente cuyo `user_id` es un administrador o un encargado (datos viejos, o
     * cargado por el token del agente) no tiene dueño a efectos del reparto: esos
     * roles no reparten.
     */
    public function duenoDe(?int $personId): ?int
    {
        if (! $personId) {
            return null;
        }

        $userId = DB::table('persons')->where('id', $personId)->value('user_id');

        return $userId && $this->esDistribuidor((int) $userId) ? (int) $userId : null;
    }

    public function nombre(?int $userId): ?string
    {
        return $userId ? DB::table('users')->where('id', $userId)->value('name') : null;
    }

    /**
     * Si un distribuidor puede usar este cliente: es de su empresa, o todavía no es
     * de nadie.
     *
     * Un cliente sin dueño (datos viejos, o cargado por un encargado) lo puede tomar
     * quien lo está atendiendo en la puerta; uno que ya es de otra empresa, no.
     */
    public function puedeUsarCliente(int $userId, ?int $personId): bool
    {
        $dueno = $this->duenoDe($personId);

        return $dueno === null || $dueno === $userId || $this->esDeMiEmpresa($dueno);
    }

    /**
     * El aviso para el distribuidor que intenta registrar un cliente de otro.
     *
     * Nombra al distribuidor y NO dice nada del cliente: ni nombre, ni dirección, ni
     * ubicación. Para el que lo intenta, ese cliente es de otra empresa.
     */
    public function avisoClienteDeOtro(?int $personId): string
    {
        $nombre = $this->nombre($this->duenoDe($personId));

        return $nombre
            ? "Este cliente ya está registrado con el distribuidor {$nombre}. Contactate con él."
            : 'Este cliente no está asignado a vos.';
    }

    /**
     * Los clientes de un distribuidor, como subconsulta para un whereIn.
     *
     * Para las consultas que acotan por cliente sin tener la columna a mano: la
     * bandeja de WhatsApp filtra conversaciones por `person_id`.
     */
    public function clientesDe(int|array $userIds)
    {
        return DB::table('persons')->select('id')->whereIn('user_id', (array) $userIds);
    }
}
