{{--
    Planta que lo fabrica y quién lo atiende en cada ciudad.

    La planta responde «¿donde lo retiro?» y los responsables «¿con quien
    hablo?». Van juntos porque son las dos preguntas que el agente no puede
    contestar solo con el catalogo.
--}}
@php
    use Webkul\User\Models\User;
    use Webkul\Warehouse\Models\Location;

    $producto = $product ?? null;

    // Las plantas son las ubicaciones del almacén (Santa Cruz): se cargan y
    // editan en Configuracion -> Almacenes -> Ubicación, con su enlace de Maps.
    $sucursales = Location::with('warehouse')->orderBy('warehouse_id')->orderBy('id')->get();

    $asesores = User::where('status', 1)->orderBy('name')->get(['id', 'name']);

    // Las ciudades más la cobertura nacional: quien está en «Bolivia» atiende
    // cualquier ciudad donde no haya alguien local para este producto.
    $ciudades = array_merge(
        config('cotizacion.ciudades', ['Santa Cruz', 'La Paz', 'Cochabamba']),
        [config('cotizacion.cobertura_nacional', 'Bolivia')]
    );

    // [ciudad => [user_id, ...]]: varias personas por ciudad se turnan.
    $asignados = old('responsables', ($producto?->responsables ?? collect())
        ->groupBy('ciudad')
        ->map(fn ($filas) => $filas->pluck('user_id')->all())
        ->all());
@endphp

<div class="box-shadow mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">
        @lang('admin::app.products.sucursal.title')
    </p>

    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
        @lang('admin::app.products.sucursal.help')
    </p>

    <input type="hidden" name="sucursal_enviada" value="1" />

    <div class="mb-4 max-w-md">
        <label for="warehouse_location_id" class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
            @lang('admin::app.products.sucursal.planta')
        </label>

        <select
            id="warehouse_location_id"
            name="warehouse_location_id"
            class="h-9 w-full cursor-pointer rounded border border-gray-200 px-2.5 text-sm text-gray-800 transition-colors hover:border-gray-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brandColor dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
        >
            <option value="">@lang('admin::app.products.sucursal.sin-planta')</option>

            @foreach ($sucursales as $s)
                <option value="{{ $s->id }}" @selected((int) old('warehouse_location_id', $producto?->warehouse_location_id) === $s->id)>
                    {{ $s->warehouse ? $s->warehouse->name.' · '.$s->name : $s->name }}
                </option>
            @endforeach
        </select>

        @if ($sucursales->isEmpty())
            <p class="mt-1 text-xs italic text-gray-400">@lang('admin::app.products.sucursal.sin-sucursales')</p>
        @endif
    </div>

    <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
        @lang('admin::app.products.sucursal.responsables')
    </p>

    <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
        @lang('admin::app.products.sucursal.responsables-help')
    </p>

    <div class="grid gap-4 md:grid-cols-4">
        @foreach ($ciudades as $ciudad)
            <fieldset>
                <legend class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                    {{ $ciudad }}
                </legend>

                @foreach ($asesores as $asesor)
                    <label class="flex cursor-pointer items-center gap-2 py-0.5 text-sm text-gray-800 dark:text-gray-300">
                        <input
                            type="checkbox"
                            name="responsables[{{ $ciudad }}][]"
                            value="{{ $asesor->id }}"
                            class="h-4 w-4 cursor-pointer rounded border-gray-300 text-brandColor focus:ring-brandColor dark:border-gray-600 dark:bg-gray-900"
                            @checked(in_array($asesor->id, array_map('intval', (array) ($asignados[$ciudad] ?? []))))
                        />
                        {{ $asesor->name }}
                    </label>
                @endforeach
            </fieldset>
        @endforeach
    </div>
</div>
