<?php

namespace Webkul\Inmobiliaria\Support;

/**
 * Accumulates what happened to every item the seeder touched, so both commands
 * can report it the same way.
 *
 * Deliberately free of any Console dependency: the rollback reuses it, and
 * keeping it plain makes it usable from tinker when verifying by hand — which
 * matters here, because decision D4 left this work without automated tests.
 */
class ResultadoCatalogo
{
    public const CREADO = 'creado';

    public const EXISTENTE = 'existente';

    public const OMITIDO = 'omitido';

    public const ELIMINADO = 'eliminado';

    /**
     * @var array<string, array<int, array{etiqueta: string, estado: string, id: ?int, motivo: ?string}>>
     */
    protected array $items = [];

    public function creado(string $entidad, string $etiqueta, ?int $id = null): void
    {
        $this->anotar($entidad, $etiqueta, self::CREADO, $id);
    }

    public function existente(string $entidad, string $etiqueta, ?int $id = null): void
    {
        $this->anotar($entidad, $etiqueta, self::EXISTENTE, $id);
    }

    public function omitido(string $entidad, string $etiqueta, string $motivo): void
    {
        $this->anotar($entidad, $etiqueta, self::OMITIDO, null, $motivo);
    }

    public function eliminado(string $entidad, string $etiqueta, ?int $id = null): void
    {
        $this->anotar($entidad, $etiqueta, self::ELIMINADO, $id);
    }

    protected function anotar(string $entidad, string $etiqueta, string $estado, ?int $id = null, ?string $motivo = null): void
    {
        $this->items[$entidad][] = [
            'etiqueta' => $etiqueta,
            'estado'   => $estado,
            'id'       => $id,
            'motivo'   => $motivo,
        ];
    }

    /**
     * @return string[]
     */
    public function entidades(): array
    {
        return array_keys($this->items);
    }

    /**
     * @return array<int, array{etiqueta: string, estado: string, id: ?int, motivo: ?string}>
     */
    public function items(string $entidad): array
    {
        return $this->items[$entidad] ?? [];
    }

    public function conteo(string $entidad, string $estado): int
    {
        return count(array_filter(
            $this->items($entidad),
            fn ($item) => $item['estado'] === $estado
        ));
    }

    public function total(string $estado): int
    {
        return array_sum(array_map(
            fn ($entidad) => $this->conteo($entidad, $estado),
            $this->entidades()
        ));
    }

    /**
     * True when the run would change something. This is the signal behind
     * success criterion SC1: a --dry-run straight after a successful apply must
     * return false here.
     */
    public function hayCambios(): bool
    {
        return $this->total(self::CREADO) > 0
            || $this->total(self::ELIMINADO) > 0;
    }

    /**
     * One-line-per-entity summary, e.g. "sources: 8 creados, 1 ya existía".
     *
     * @return string[]
     */
    public function resumen(): array
    {
        $lineas = [];

        foreach ($this->entidades() as $entidad) {
            $partes = [];

            foreach ([
                self::CREADO    => 'creado(s)',
                self::ELIMINADO => 'eliminado(s)',
                self::EXISTENTE => 'ya existía(n)',
                self::OMITIDO   => 'omitido(s)',
            ] as $estado => $rotulo) {
                if (($n = $this->conteo($entidad, $estado)) > 0) {
                    $partes[] = "{$n} {$rotulo}";
                }
            }

            $lineas[] = sprintf('%-18s %s', $entidad.':', implode(', ', $partes) ?: 'nada');
        }

        return $lineas;
    }
}
