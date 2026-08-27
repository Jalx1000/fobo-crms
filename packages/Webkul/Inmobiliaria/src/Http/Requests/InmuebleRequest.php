<?php

namespace Webkul\Inmobiliaria\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InmuebleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cfg = config('inmobiliaria.inmuebles');
        $id = $this->route('id');

        return [
            // Único incluso contra los borrados lógicamente: reciclar un código
            // haría que el cliente reciba la ficha equivocada al citarlo.
            'codigo' => [
                'required', 'string', 'max:32',
                Rule::unique('inmuebles', 'codigo')->ignore($id),
            ],

            'operacion' => ['required', Rule::in($cfg['operaciones'])],
            'tipo'      => ['required', Rule::in(array_keys($cfg['tipos']))],
            'estado'    => ['required', Rule::in($cfg['estados'])],

            'titulo'      => ['required', 'string', 'max:160'],
            'destacado'   => ['nullable', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string'],

            'zona'       => ['required', 'string', 'max:120'],
            'barrio'     => ['nullable', 'string', 'max:120'],
            'referencia' => ['nullable', 'string', 'max:200'],

            'direccion_exacta' => ['nullable', 'string', 'max:255'],
            'google_maps_url'  => ['nullable', 'url', 'max:500'],
            'latitud'          => ['nullable', 'numeric', 'between:-90,90'],
            'longitud'         => ['nullable', 'numeric', 'between:-180,180'],
            'punto_encuentro'  => ['nullable', 'string', 'max:255'],

            'precio'           => ['required', 'numeric', 'min:0'],
            'precio_anterior'  => ['nullable', 'numeric', 'min:0', 'gt:precio'],
            'moneda'           => ['required', Rule::in($cfg['monedas'])],
            'modalidad_cambio' => ['nullable', Rule::in(['solo_usd', 'tc_oficial', 'tc_paralelo'])],
            'negociable'       => ['nullable', 'boolean'],

            'dormitorios'    => ['nullable', 'integer', 'min:0', 'max:255'],
            'banos'          => ['nullable', 'integer', 'min:0', 'max:255'],
            'parqueos'       => ['nullable', 'integer', 'min:0', 'max:255'],
            'm2_construidos' => ['nullable', 'numeric', 'min:0'],
            'm2_terreno'     => ['nullable', 'numeric', 'min:0'],

            // Características específicas del tipo (D13): pares clave/valor
            // libres, porque un galpón y una casa no describen lo mismo.
            'caracteristicas'         => ['nullable', 'array'],
            'caracteristicas.clave'   => ['nullable', 'array'],
            'caracteristicas.clave.*' => ['nullable', 'string', 'max:60'],
            'caracteristicas.valor'   => ['nullable', 'array'],
            'caracteristicas.valor.*' => ['nullable', 'string', 'max:255'],

            // El titular tiene que ser un asesor de verdad: es quien recibe el
            // handoff del agente. Un administrador que administra el CRM no
            // necesariamente muestra casas.
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->where(
                    fn ($q) => $q->whereIn('role_id', function ($sub) {
                        $sub->select('id')->from('roles')->where('name', 'Asesor');
                    })
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.unique'          => 'Ya existe un inmueble con ese código.',
            'tipo.in'                => 'El tipo no está en el catálogo. Agregalo en Config/inmuebles.php si es un tipo nuevo que vende la oficina.',
            'operacion.in'           => 'La operación debe ser venta, alquiler o anticrético.',
            'estado.in'              => 'Estado inválido.',
            'moneda.in'              => 'La moneda debe ser USD o BOB.',
            'modalidad_cambio.in'    => 'Modalidad de cambio inválida.',
            'google_maps_url.url'    => 'El link de Google Maps no es una URL válida.',
            'precio_anterior.gt'     => 'El precio anterior debe ser mayor al actual: sirve para mostrar una rebaja.',
            'user_id.exists'         => 'El titular debe ser un usuario con rol Asesor.',
        ];
    }

    public function attributes(): array
    {
        return [
            'codigo'      => 'código', 'operacion' => 'operación', 'titulo' => 'título',
            'descripcion' => 'descripción', 'direccion_exacta' => 'dirección exacta',
            'user_id'     => 'asesor titular', 'm2_construidos' => 'm² construidos',
            'm2_terreno'  => 'm² de terreno', 'banos' => 'baños',
        ];
    }

    /**
     * Normaliza lo que manda el navegador antes de validar.
     *
     * Dos cosas que el formulario HTML hace y el modelo no tolera:
     *
     *  - Un checkbox desmarcado no manda nada. Sin el boolean explícito,
     *    desmarcarlo nunca guardaría el false.
     *  - Un campo numérico vacío manda `""`, no null. El cast `decimal` de
     *    Eloquent revienta con "Unable to cast value to a decimal" al recibir
     *    cadena vacía. Esta app no tiene el middleware ConvertEmptyStringsToNull
     *    registrado (`app/Http/Kernel.php` solo trae TrimStrings), así que la
     *    conversión hay que hacerla acá.
     */
    protected function prepareForValidation(): void
    {
        $numericos = [
            'precio', 'precio_anterior', 'latitud', 'longitud',
            'dormitorios', 'banos', 'parqueos', 'm2_construidos', 'm2_terreno',
        ];

        $limpios = [];

        foreach ($numericos as $campo) {
            if ($this->has($campo) && $this->input($campo) === '') {
                $limpios[$campo] = null;
            }
        }

        $this->merge($limpios + ['negociable' => $this->boolean('negociable')]);
    }
}
