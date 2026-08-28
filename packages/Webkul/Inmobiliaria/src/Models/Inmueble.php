<?php

namespace Webkul\Inmobiliaria\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Webkul\Inmobiliaria\Contracts\Inmueble as InmuebleContract;
use Webkul\Lead\Models\LeadProxy;
use Webkul\User\Models\UserProxy;

class Inmueble extends Model implements InmuebleContract
{
    use SoftDeletes;

    protected $table = 'inmuebles';

    protected $fillable = [
        'codigo', 'operacion', 'tipo', 'estado', 'titulo', 'destacado', 'descripcion',
        'zona', 'barrio', 'referencia',
        'direccion_exacta', 'google_maps_url', 'latitud', 'longitud', 'punto_encuentro',
        'precio', 'precio_anterior', 'moneda', 'modalidad_cambio', 'negociable',
        'dormitorios', 'banos', 'parqueos', 'm2_construidos', 'm2_terreno', 'caracteristicas',
        'user_id', 'publicado_at',
    ];

    protected $casts = [
        'caracteristicas' => 'array',
        'negociable'      => 'boolean',
        'precio'          => 'decimal:2',
        'precio_anterior' => 'decimal:2',
        'latitud'         => 'decimal:7',
        'longitud'        => 'decimal:7',
        'publicado_at'    => 'datetime',
    ];

    /**
     * Campos que NUNCA salen en la ficha que ve el agente de IA.
     *
     * No es una convención ni un recordatorio: es la lista que consume
     * `paraAgente()`. La ubicación exacta solo viaja por el endpoint que la
     * envía al cliente, sin pasar por el LLM (decisión D6 de la spec).
     *
     * @var array<int, string>
     */
    public const CAMPOS_GATEADOS = [
        'direccion_exacta',
        'google_maps_url',
        'latitud',
        'longitud',
    ];

    /**
     * El asesor titular: quien captó el inmueble y quien lo muestra.
     *
     * Siempre es este usuario quien recibe el handoff, sin fallback a otro
     * asesor — derivar a un tercero rompería la promesa que el agente acaba de
     * hacerle al cliente ("le paso con <nombre>").
     */
    public function asesor(): BelongsTo
    {
        return $this->belongsTo(UserProxy::modelClass(), 'user_id');
    }

    /**
     * Los leads que se interesaron por este inmueble.
     *
     * Se guarda como vínculo y no como texto en la descripción del lead para
     * poder responder la pregunta que de verdad importa: qué inmuebles generan
     * consultas y cuáles no mueve nadie.
     */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(LeadProxy::modelClass(), 'inmueble_lead')
            ->withPivot('origen')
            ->withTimestamps();
    }

    /**
     * La galería, en el orden que fijó el asesor arrastrando.
     *
     * Ordena SOLO por `orden`, no por portada. Que la portada salga primero es
     * una regla del envío —vive en `InmuebleMediaRepository::paraEnviar()`— y no
     * de la galería: si también mandara acá, arrastrar la portada a otra
     * posición no tendría efecto visible y parecería que no se guardó.
     */
    public function medias(): HasMany
    {
        return $this->hasMany(InmuebleMediaProxy::modelClass(), 'inmueble_id')
            ->orderBy('orden')
            ->orderBy('id');
    }

    /**
     * La foto que representa al inmueble donde no entra la galería completa.
     */
    public function portada()
    {
        $fotos = $this->medias->where('tipo', 'foto');

        return $fotos->firstWhere('es_portada', true) ?? $fotos->first();
    }

    /**
     * Solo los inmuebles que el agente puede ofrecer.
     *
     * La lista sale de config, no de un literal, porque es una decisión
     * comercial: hoy solo 'disponible'.
     */
    public function scopeOfrecibles(Builder $query): Builder
    {
        return $query->whereIn(
            'estado',
            (array) config('inmobiliaria.inmuebles.estados_buscables', ['disponible'])
        );
    }

    /**
     * La línea que el agente usa para describir el inmueble.
     *
     * El handoff pide "una línea real de la ficha, no un adjetivo genérico", y
     * la primera oración de la descripción cumple justamente eso: es texto del
     * anuncio, escrito por quien lo publicó.
     *
     * La columna `destacado` sigue existiendo y tiene prioridad —hay fichas que
     * ya la traen y el seeder la usa— pero salió del formulario: era un campo
     * más que llenar para decir algo que la descripción ya dice.
     */
    public function lineaDestacada(): ?string
    {
        if (filled($this->destacado)) {
            return $this->destacado;
        }

        if (blank($this->descripcion)) {
            return null;
        }

        $primera = preg_split('/(?<=[.!?])\s+/u', trim($this->descripcion), 2)[0] ?? '';

        return $primera !== '' ? mb_substr($primera, 0, 200) : null;
    }

    /**
     * Representación segura para el agente de IA.
     *
     * Construida por lista blanca a propósito: agregar una columna a la tabla
     * no la expone sola. Es lo que hace que D6 sea estructural y no una regla
     * que alguien pueda olvidar.
     */
    public function paraAgente(bool $completo = false): array
    {
        $ficha = [
            'codigo'           => $this->codigo,
            'operacion'        => $this->operacion,
            'tipo'             => $this->tipo,
            'titulo'           => $this->titulo,
            'zona'             => $this->zona,
            'barrio'           => $this->barrio,
            'referencia'       => $this->referencia,
            'dormitorios'      => $this->dormitorios,
            'banos'            => $this->banos,
            'parqueos'         => $this->parqueos,
            'm2_construidos'   => $this->m2_construidos !== null ? (float) $this->m2_construidos : null,
            'm2_terreno'       => $this->m2_terreno !== null ? (float) $this->m2_terreno : null,
            'precio'           => (float) $this->precio,
            'precio_anterior'  => $this->precio_anterior !== null ? (float) $this->precio_anterior : null,
            'moneda'           => $this->moneda,
            'modalidad_cambio' => $this->modalidad_cambio,
            'negociable'       => $this->negociable,
            'destacado'        => $this->lineaDestacada(),
            'caracteristicas'  => $this->caracteristicas ?? [],
            'tiene_fotos'      => $this->medias->where('tipo', 'foto')->isNotEmpty(),
            'tiene_plano'      => $this->medias->where('tipo', 'plano')->isNotEmpty(),
            'tiene_tour'       => $this->medias->where('tipo', 'tour')->isNotEmpty(),
        ];

        if (! $completo) {
            return $ficha;
        }

        return $ficha + [
            'descripcion'     => $this->descripcion,
            'punto_encuentro' => $this->punto_encuentro,
            'asesor'          => [
                'nombre'   => $this->asesor?->name,
                'telefono' => $this->asesor?->phone,
            ],
        ];
    }
}
