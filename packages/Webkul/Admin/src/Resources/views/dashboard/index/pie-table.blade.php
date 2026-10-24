{{--
    Tabla debajo de una torta del tablero: reemplaza la lista de etiquetas.
    Una fila por porción, con el color de la porción, y una fila de total.

    Recibe expresiones Vue (se evalúan en el componente que la incluye). Van
    con {!! !!} y v-text: dentro de un x-template el navegador no decodifica
    entidades, así que un {{ }} de Blade rompería las comillas.
      $rows          array de filas
      $label         nombre de la fila (variable `row`)
      $labelHeader   título de la primera columna
      $value         número de la fila (variable `row`)
      $valueHeader   título de la columna del número
      $total         total de la torta: el mismo número que su tarjeta
      $extra         opcional: ['header' => ..., 'value' => ..., 'total' => ...]
      $colors        colores de las porciones, en el mismo orden que $rows
--}}
@php
    $extra = $extra ?? null;
@endphp

<div class="mt-4 max-h-80 overflow-y-auto border-t border-gray-200 dark:border-gray-800">
    <table class="w-full text-left">
        <thead class="sticky top-0 bg-white dark:bg-gray-900">
            <tr class="border-b border-gray-200 dark:border-gray-800">
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $labelHeader }}</th>
                <th scope="col" class="whitespace-nowrap py-2.5 pl-2 pr-4 text-right text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $valueHeader }}</th>

                @if ($extra)
                    <th scope="col" class="whitespace-nowrap py-2.5 pl-2 pr-4 text-right text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $extra['header'] }}</th>
                @endif
            </tr>
        </thead>

        <tbody>
            <tr
                v-for="(row, index) in {!! $rows !!}"
                :key="index"
                class="border-b border-gray-100 dark:border-gray-800"
            >
                <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 h-3 w-3 shrink-0 rounded-sm" :style="{ backgroundColor: {!! $colors !!}[index] }"></span>
                        <span class="break-words" v-text="{!! $label !!}"></span>
                    </div>
                </td>
                <td class="whitespace-nowrap py-2 pl-2 pr-4 text-right text-xs font-semibold text-gray-800 dark:text-white" v-text="{!! $value !!}"></td>

                @if ($extra)
                    <td class="whitespace-nowrap py-2 pl-2 pr-4 text-right text-xs text-gray-600 dark:text-gray-300" v-text="{!! $extra['value'] !!}"></td>
                @endif
            </tr>
        </tbody>

        <tfoot class="sticky bottom-0 bg-gray-50 dark:bg-gray-950">
            <tr>
                <th scope="row" class="px-4 py-2.5 text-xs font-semibold text-gray-800 dark:text-white">Total</th>
                <td class="whitespace-nowrap py-2.5 pl-2 pr-4 text-right text-xs font-semibold text-gray-800 dark:text-white" v-text="{!! $total !!}"></td>

                @if ($extra)
                    <td class="whitespace-nowrap py-2.5 pl-2 pr-4 text-right text-xs font-semibold text-gray-800 dark:text-white" v-text="{!! $extra['total'] !!}"></td>
                @endif
            </tr>
        </tfoot>
    </table>
</div>
