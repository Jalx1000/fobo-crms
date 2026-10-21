{{--
    Botón «←» del celular (debajo de lg) para volver al listado del módulo.

    Vuelve a la página anterior si era ese listado -conserva ciudad, filtros
    y vista-; si se llegó de otro lado (un enlace, el formulario recién
    guardado, la ficha), abre el listado con `parametros`.

    Uso: <x-admin::layouts.volver listado="admin.leads.index" :parametros="['pipeline_id' => $lead->lead_pipeline_id]" />
--}}
@props([
    'listado',
    'parametros' => [],
])

@php
    $lista = route($listado);

    $anterior = url()->previous();

    $resto = \Illuminate\Support\Str::after($anterior, $lista);

    $esLaLista = \Illuminate\Support\Str::startsWith($anterior, $lista)
        && ($resto === '' || \Illuminate\Support\Str::startsWith($resto, ['?', '#']));

    $volverA = $esLaLista ? $anterior : route($listado, array_filter($parametros, fn ($valor) => $valor !== null && $valor !== ''));
@endphp

<a
    href="{{ $volverA }}"
    aria-label="@lang('admin::app.layouts.volver')"
    {{ $attributes->merge(['class' => '-ml-2 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-gray-700 transition hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brandColor dark:text-gray-200 dark:hover:bg-gray-800 lg:hidden']) }}
>
    <span class="icon-left-arrow text-2xl"></span>
</a>
