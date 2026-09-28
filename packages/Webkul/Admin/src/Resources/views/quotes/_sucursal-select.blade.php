{{--
    Sucursal: un select simple con las plantas.

    El atributo es `lookup` a `warehouses` —para que las plantas se carguen una
    sola vez en Configuración → Almacenes y no haya una lista paralela de
    opciones que se desincronice—, pero un buscador con autocompletar para tres
    plantas es más trabajo que elegir de una lista. Así que el dato sigue siendo
    el id del almacén y solo cambia cómo se pide.

    Se renderiza a mano porque el bloque de atributos personalizados dibuja el
    lookup: por eso `sucursal` se excluye de ese bloque en create y edit.
--}}
@php
    $plantas = \Webkul\Warehouse\Models\Warehouse::orderBy('id')->get();

    $sucursalActual = old('sucursal', $quote->sucursal ?? null);
@endphp

<x-admin::form.control-group class="w-full">
    <x-admin::form.control-group.label>
        @lang('admin::app.quotes.index.pdf.sucursal')
    </x-admin::form.control-group.label>

    <x-admin::form.control-group.control
        type="select"
        name="sucursal"
        id="sucursal"
        :label="trans('admin::app.quotes.index.pdf.sucursal')"
        :value="$sucursalActual"
    >
        <option value="">@lang('admin::app.quotes.create.sucursal-sin-asignar')</option>

        @foreach ($plantas as $planta)
            {{-- La línea solo se agrega si el nombre no la dice ya: hoy las
                 plantas se llaman «Sucursal Tapas - Santa Cruz», y repetirla
                 daba «Sucursal Tapas - Santa Cruz · Tapas». --}}
            <option value="{{ $planta->id }}" @selected((int) $sucursalActual === (int) $planta->id)>
                {{ $planta->linea && ! Str::contains($planta->name, $planta->linea)
                    ? $planta->name.' · '.$planta->linea
                    : $planta->name }}
            </option>
        @endforeach
    </x-admin::form.control-group.control>

    <x-admin::form.control-group.error control-name="sucursal" />
</x-admin::form.control-group>
