<?php

namespace Webkul\Inmobiliaria\Support;

use Webkul\Core\Models\CoreConfigProxy;

/**
 * Record of what the setup command actually created.
 *
 * The rollback cannot decide what to delete by comparing against the catalogue:
 * "Web" is listed there and already existed on this instance as
 * lead_sources.id = 2, so a catalogue-driven rollback would destroy a row that
 * was never ours. The same applies to anything the client creates by hand under
 * a name the catalogue also uses.
 *
 * So the setup records the ids it created and the rollback deletes only those.
 *
 * Persisted in core_config rather than a file under storage/ because it must
 * stay in sync with the database it describes: living in the same database
 * means a mysqldump captures backup and manifest together, and a container
 * redeploy cannot leave the rollback blind.
 */
class Manifiesto
{
    /**
     * core_config key holding the manifest.
     */
    public const CODE = 'inmobiliaria.setup.manifest';

    /**
     * Payload schema version, so a future format change can be detected rather
     * than silently misread.
     */
    public const VERSION = 1;

    /**
     * The entities the manifest tracks, in deletion-safe order: children first,
     * so a rollback that walks this order never orphans a row.
     */
    public const ENTIDADES = [
        'attribute_options',
        'attributes',
        'stages',
        'pipelines',
        'sources',
        'types',
    ];

    /**
     * @param  array<string, int[]>  $creados
     */
    protected function __construct(
        protected array $creados = [],
        protected ?string $aplicadoEn = null,
        protected bool $existePersistido = false,
        protected array $previo = [],
    ) {}

    /**
     * Load the manifest, or an empty one when nothing has been applied yet.
     *
     * A corrupt or unknown-version payload is treated as absent: the rollback
     * then refuses to guess, which is the safe outcome.
     */
    public static function leer(): self
    {
        $fila = CoreConfigProxy::modelClass()::query()
            ->where('code', self::CODE)
            ->first();

        if (! $fila) {
            return new self;
        }

        $datos = json_decode((string) $fila->value, true);

        if (! is_array($datos) || ($datos['version'] ?? null) !== self::VERSION) {
            return new self;
        }

        $creados = [];

        foreach (self::ENTIDADES as $entidad) {
            $creados[$entidad] = array_values(array_unique(array_map(
                'intval',
                $datos['created'][$entidad] ?? []
            )));
        }

        return new self($creados, $datos['applied_at'] ?? null, true, $datos['previous'] ?? []);
    }

    /**
     * Note that we created this row. Ignored if already recorded, so a retry
     * after a partial failure cannot double-register an id.
     */
    public function registrar(string $entidad, int $id): void
    {
        if (! in_array($entidad, self::ENTIDADES, true)) {
            throw new \InvalidArgumentException("Entidad desconocida en el manifiesto: {$entidad}");
        }

        if (! in_array($id, $this->creados[$entidad] ?? [], true)) {
            $this->creados[$entidad][] = $id;
        }
    }

    /**
     * Remember a piece of pre-existing state we are about to overwrite, so the
     * rollback can put it back. Only recorded the first time: a second setup run
     * must not overwrite the original value with our own.
     */
    public function registrarPrevio(string $clave, mixed $valor): void
    {
        if (! array_key_exists($clave, $this->previo)) {
            $this->previo[$clave] = $valor;
        }
    }

    public function previo(string $clave): mixed
    {
        return $this->previo[$clave] ?? null;
    }

    /**
     * @return int[]
     */
    public function ids(string $entidad): array
    {
        return $this->creados[$entidad] ?? [];
    }

    public function total(): int
    {
        return array_sum(array_map('count', $this->creados));
    }

    /**
     * Whether a manifest row exists at all — distinct from it being empty.
     * The rollback needs the difference: "never applied" and "applied but
     * created nothing" call for different messages.
     */
    public function existe(): bool
    {
        return $this->existePersistido;
    }

    public function aplicadoEn(): ?string
    {
        return $this->aplicadoEn;
    }

    /**
     * Persist, merging with whatever is stored right now.
     *
     * Merging rather than replacing means a partial run followed by a second
     * one keeps the trail of the first; otherwise the rows created before a
     * failure would become unreachable to the rollback.
     */
    public function guardar(): void
    {
        $persistido = self::leer();

        $union = [];

        foreach (self::ENTIDADES as $entidad) {
            $union[$entidad] = array_values(array_unique(array_merge(
                $persistido->ids($entidad),
                $this->ids($entidad)
            )));
        }

        CoreConfigProxy::modelClass()::query()->updateOrCreate(
            ['code' => self::CODE],
            ['value' => json_encode([
                'version'    => self::VERSION,
                'applied_at' => now()->toIso8601String(),
                'created'    => $union,
                'previous'   => $this->previo + $persistido->previoTodo(),
            ], JSON_UNESCAPED_UNICODE)],
        );

        $this->creados = $union;
        $this->existePersistido = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function previoTodo(): array
    {
        return $this->previo;
    }

    /**
     * Drop the manifest. Called by the rollback once every recorded row is gone.
     */
    public function olvidar(): void
    {
        CoreConfigProxy::modelClass()::query()
            ->where('code', self::CODE)
            ->delete();

        $this->creados = [];
        $this->existePersistido = false;
    }
}
