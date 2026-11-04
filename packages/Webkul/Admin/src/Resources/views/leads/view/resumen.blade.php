{{--
    Detalle del pedido: una fila por producto, con su cantidad y su importe.

    Calculado, no cargado a mano: sale de las cotizaciones vinculadas o, si no
    hay, de los productos del pedido (ver ResumenPedido). Por eso no se puede
    editar acá - se edita la cotización o los productos, y esto sigue.
--}}
@php
    $resumen = app(\Webkul\Lead\Services\ResumenPedido::class)->de($lead);
@endphp

<div class="flex w-full flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-800">
    <div class="flex items-baseline justify-between gap-4">
        <h4 class="font-semibold dark:text-white">@lang('admin::app.leads.view.resumen.title')</h4>

        <span class="text-xs text-gray-500 dark:text-gray-400">
            @lang('admin::app.leads.view.resumen.origen-'.$resumen['origen'])
        </span>
    </div>

    @if ($resumen['origen'] === 'vacio')
        <p class="text-sm italic text-gray-400">@lang('admin::app.leads.view.resumen.vacio')</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-gray-500 dark:text-gray-400">
                        <th class="py-1 pr-2 text-left font-medium">@lang('admin::app.leads.view.resumen.tipo')</th>

                        <th class="px-2 py-1 text-right font-medium">@lang('admin::app.leads.view.resumen.cantidad')</th>

                        <th class="py-1 pl-2 text-right font-medium">@lang('admin::app.leads.view.resumen.importe')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($resumen['lineas'] as $linea)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1.5 pr-2 text-gray-800 dark:text-gray-300">{{ $linea['producto'] }}</td>

                            <td class="px-2 py-1.5 text-right text-gray-800 dark:text-gray-300">
                                {{ number_format($linea['cantidad'], 0, ',', '.') }}
                            </td>

                            <td class="py-1.5 pl-2 text-right text-gray-800 dark:text-gray-300">
                                Bs {{ number_format($linea['importe'], 2, ',', '.') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>

                <tfoot>
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="py-1.5 pr-2 font-medium text-gray-700 dark:text-gray-300">
                            @lang('admin::app.leads.view.resumen.valor')
                        </td>

                        <td class="px-2 py-1.5 text-right text-gray-700 dark:text-gray-300">
                            {{ number_format($resumen['cantidad'], 0, ',', '.') }}
                        </td>

                        <td class="py-1.5 pl-2 text-right font-bold text-brandColor">
                            Bs {{ number_format($resumen['valor'], 2, ',', '.') }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
