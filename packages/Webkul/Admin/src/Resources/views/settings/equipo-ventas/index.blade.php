{{--
    Equipo de ventas: la matriz producto × ciudad, de un vistazo. Solo lectura:
    se edita en cada usuario (sección «Atiende») o en cada producto.
--}}
<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.settings.equipo-ventas.index.title')
    </x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:flex-wrap max-lg:gap-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex flex-col gap-2">
                <x-admin::breadcrumbs name="settings.equipo_ventas" />

                <div class="text-xl font-bold dark:text-white">
                    @lang('admin::app.settings.equipo-ventas.index.title')
                </div>
            </div>

            <a href="{{ route('admin.settings.users.index') }}" class="secondary-button">
                @lang('admin::app.layouts.users')
            </a>
        </div>

        <p class="text-sm text-gray-600 dark:text-gray-300">
            @lang('admin::app.settings.equipo-ventas.index.help')
        </p>

        {{-- Por producto y ciudad: la casilla vacía se ve enseguida. --}}
        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">
                @lang('admin::app.settings.equipo-ventas.index.por-producto')
            </p>

            @if ($productos->isEmpty())
                <p class="text-sm italic text-gray-400">@lang('admin::app.settings.equipo-ventas.index.sin-productos')</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                <th class="py-2 pr-3 font-medium"></th>

                                @foreach ($ciudades as $ciudad)
                                    <th class="px-3 py-2 font-medium">{{ $ciudad }}</th>
                                @endforeach
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($productos as $producto)
                                <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                    <td class="py-2 pr-3 font-medium text-gray-800 dark:text-white">
                                        <a href="{{ route('admin.products.edit', $producto->id) }}" class="hover:underline">{{ $producto->name }}</a>
                                    </td>

                                    @foreach ($ciudades as $ciudad)
                                        <td class="px-3 py-2 text-gray-800 dark:text-gray-300">
                                            @forelse ($matriz[$producto->id][$ciudad] as $u)
                                                <a
                                                    href="{{ route('admin.settings.users.index', ['action' => 'edit', 'id' => $u->id]) }}"
                                                    class="block hover:underline"
                                                >{{ $u->name }}</a>
                                            @empty
                                                <span class="text-xs italic text-gray-400">@lang('admin::app.settings.equipo-ventas.index.nadie')</span>
                                            @endforelse
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Por persona: cómo lo manda Imprimir. --}}
        <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">
                @lang('admin::app.settings.equipo-ventas.index.por-persona')
            </p>

            @if ($asesores->isEmpty())
                <p class="text-sm italic text-gray-400">@lang('admin::app.settings.equipo-ventas.index.sin-asesores')</p>
            @else
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($asesores as $asesor)
                        <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-800">
                            <div class="mb-1 flex items-baseline justify-between gap-2">
                                <p class="font-semibold text-gray-800 dark:text-white">{{ $asesor->name }}</p>

                                <a
                                    href="{{ route('admin.settings.users.index', ['action' => 'edit', 'id' => $asesor->id]) }}"
                                    class="text-xs text-brandColor hover:underline"
                                >@lang('admin::app.settings.equipo-ventas.index.editar')</a>
                            </div>

                            <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ $asesor->email }}
                                @if ($asesor->telefono) · {{ $asesor->telefono }} @endif
                                @if ($asesor->horario) · {{ $asesor->horario }} @endif
                            </p>

                            <ul class="text-sm text-gray-800 dark:text-gray-300">
                                @foreach ($asesor->atiende->groupBy('product_id') as $filas)
                                    <li>
                                        {{ $filas->first()->product?->name }}
                                        <span class="text-gray-500 dark:text-gray-400">· {{ $filas->pluck('ciudad')->join(', ') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-admin::layouts>
