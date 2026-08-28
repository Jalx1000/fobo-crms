<?php

namespace Webkul\Inmobiliaria\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Webkul\Core\Eloquent\Repository;

class InmuebleRepository extends Repository
{
    /**
     * Searchable fields.
     */
    protected $fieldSearchable = [
        'codigo', 'titulo', 'zona', 'barrio', 'tipo', 'operacion', 'estado',
    ];

    public function model(): string
    {
        return 'Webkul\Inmobiliaria\Contracts\Inmueble';
    }

    /**
     * Búsqueda para el agente de IA.
     *
     * Devuelve solo lo ofrecible (config, hoy 'disponible') y aplica la regla de
     * moneda de D4: el rango de precio filtra ÚNICAMENTE las fichas cuya moneda
     * coincide con la pedida; las de la otra moneda se devuelven igual, con su
     * precio literal, porque no convertimos entre monedas en ningún punto.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function buscar(array $filtros): Collection
    {
        $config = config('inmobiliaria.inmuebles');

        $limite = min(
            (int) ($filtros['limite'] ?? $config['limite_busqueda_default']),
            (int) $config['limite_busqueda_max']
        );

        $query = $this->model->newQuery()->with('medias')->ofrecibles();

        foreach (['operacion', 'tipo'] as $campo) {
            if (filled($filtros[$campo] ?? null)) {
                $query->where($campo, $filtros[$campo]);
            }
        }

        // Una zona escrita por el cliente puede ser zona o barrio: "Zona Norte"
        // y "Hamacas" son ambas respuestas válidas a "¿dónde buscás?".
        if (filled($filtros['zonas'] ?? null)) {
            $query->where(function ($q) use ($filtros) {
                foreach ((array) $filtros['zonas'] as $zona) {
                    $q->orWhere('zona', 'like', '%'.$zona.'%')
                        ->orWhere('barrio', 'like', '%'.$zona.'%');
                }
            });
        }

        /*
        | Mínimos. Cuál importa depende del tipo: para una casa es dormitorios,
        | para un lote es la superficie del terreno —un lote no tiene
        | dormitorios y filtrar por ellos lo dejaría siempre afuera—. Se aplican
        | solo los que vengan, así que cada tipo usa los suyos.
        */
        $minimos = [
            'dormitorios_min'    => 'dormitorios',
            'parqueos_min'       => 'parqueos',
            'm2_terreno_min'     => 'm2_terreno',
            'm2_construidos_min' => 'm2_construidos',
        ];

        foreach ($minimos as $filtro => $columna) {
            if (filled($filtros[$filtro] ?? null)) {
                $query->where($columna, '>=', $filtros[$filtro]);
            }
        }

        $moneda = $filtros['moneda'] ?? $config['moneda_por_defecto'];
        $min = $filtros['precio_min'] ?? null;
        $max = $filtros['precio_max'] ?? null;

        if (filled($min) || filled($max)) {
            $query->where(function ($q) use ($moneda, $min, $max) {
                $q->where(function ($coincide) use ($moneda, $min, $max) {
                    $coincide->where('moneda', $moneda);

                    if (filled($min)) {
                        $coincide->where('precio', '>=', $min);
                    }

                    if (filled($max)) {
                        $coincide->where('precio', '<=', $max);
                    }
                })
                    // Otra moneda: no se filtra por precio, se muestra literal.
                    ->orWhere('moneda', '!=', $moneda);
            });
        }

        // Primero las de la moneda que pidió el cliente: son las que puede
        // comparar sin hacer cuentas.
        return $query
            ->orderByRaw('CASE WHEN moneda = ? THEN 0 ELSE 1 END', [$moneda])
            ->orderBy('precio')
            ->limit($limite)
            ->get();
    }

    /**
     * Busca por el código público — el único identificador que el LLM maneja.
     */
    public function porCodigo(string $codigo, bool $soloOfrecibles = true)
    {
        $query = $this->model->newQuery()->with(['medias', 'asesor'])
            ->where('codigo', $codigo);

        if ($soloOfrecibles) {
            $query->ofrecibles();
        }

        return $query->first();
    }

    /**
     * Propone el siguiente código libre (C21-0001, C21-0002, ...).
     *
     * Es solo una propuesta: el asesor lo puede pisar con el código del anuncio
     * ya publicado (D9), que es el que el cliente le va a decir al agente. Por
     * eso el hueco se busca de verdad en vez de contar filas: los códigos
     * externos conviven con los generados y no siguen la serie.
     */
    public function siguienteCodigo(): string
    {
        $cfg = config('inmobiliaria.inmuebles.codigo');
        $prefijo = $cfg['prefijo'];

        $ultimo = $this->model->newQuery()
            ->withTrashed()
            ->where('codigo', 'like', $prefijo.'%')
            ->orderByRaw('LENGTH(codigo) DESC, codigo DESC')
            ->value('codigo');

        $siguiente = $ultimo
            ? ((int) substr($ultimo, strlen($prefijo))) + 1
            : 1;

        return $prefijo.str_pad((string) $siguiente, (int) $cfg['relleno'], '0', STR_PAD_LEFT);
    }

    /**
     * Zonas y barrios con inmuebles ofrecibles, para poblar filtros de la UI.
     *
     * @return array<int, string>
     */
    public function zonasDisponibles(): array
    {
        return $this->model->newQuery()
            ->ofrecibles()
            ->select('zona')
            ->distinct()
            ->orderBy('zona')
            ->pluck('zona')
            ->all();
    }
}
