{{--
    Condiciones de entrega del producto.

    Son del producto y no de la cotizacion: las tapas impresas tardan 20 dias se
    las cotice a quien se las cotice. La cotizacion las PROPONE desde aca y el
    asesor las ajusta si hace falta.
--}}
@php
    $producto = $product ?? null;

    $lugares = ['Puesto almacén cliente', 'Recoge en planta', 'A domicilio'];
@endphp

<div class="box-shadow mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <p class="mb-1 text-base font-semibold text-gray-800 dark:text-white">
        @lang('admin::app.products.entrega.title')
    </p>

    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
        @lang('admin::app.products.entrega.help')
    </p>

    <div class="grid gap-3 md:grid-cols-3">
        <div>
            <label for="tiempo_entrega" class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                @lang('admin::app.products.entrega.tiempo')
            </label>

            <input
                id="tiempo_entrega"
                name="tiempo_entrega"
                value="{{ old('tiempo_entrega', $producto?->tiempo_entrega ?? 'Inmediata') }}"
                placeholder="@lang('admin::app.products.entrega.tiempo-placeholder')"
                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
            />
        </div>

        <div>
            <label for="lugar_entrega" class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                @lang('admin::app.products.entrega.lugar')
            </label>

            <select
                id="lugar_entrega"
                name="lugar_entrega"
                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
            >
                @foreach ($lugares as $lugar)
                    <option value="{{ $lugar }}" @selected(old('lugar_entrega', $producto?->lugar_entrega ?? $lugares[0]) === $lugar)>
                        {{ $lugar }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="entrega_domicilio_desde" class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                @lang('admin::app.products.entrega.domicilio-desde')
            </label>

            <input
                id="entrega_domicilio_desde"
                name="entrega_domicilio_desde"
                type="number"
                min="1"
                value="{{ old('entrega_domicilio_desde', $producto?->entrega_domicilio_desde) }}"
                placeholder="@lang('admin::app.products.entrega.domicilio-desde-placeholder')"
                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
            />

            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                @lang('admin::app.products.entrega.domicilio-desde-help')
            </p>
        </div>
    </div>
</div>
