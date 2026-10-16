{{--
    Selector «Ejecutivo comercial», al lado del de ciudad. Un <select> que
    navega, como el de canal: la elección queda en la URL (`ejecutivo`) y el
    kanban y la lista la reenvían a su endpoint (FiltroEjecutivo).
--}}
@php
    $ejecutivos = \Webkul\Admin\Helpers\FiltroEjecutivo::opciones();
    $ejecutivoElegido = \Webkul\Admin\Helpers\FiltroEjecutivo::elegido();
    $parametro = \Webkul\Admin\Helpers\FiltroEjecutivo::PARAMETRO;
@endphp

<select
    aria-label="Ejecutivo comercial"
    class="w-max cursor-pointer appearance-none rounded-md border bg-white px-2.5 py-[7px] leading-6 text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 {{ $ejecutivoElegido ? 'border-brandColor text-brandColor' : '' }}"
    onchange="window.location = this.value"
>
    {{-- Se conservan los demás parámetros: cambiar de ejecutivo no resetea la
         ciudad, la vista ni las fechas. --}}
    <option value="{{ route('admin.leads.index', request()->except($parametro)) }}">
        Ejecutivo comercial
    </option>

    @foreach ($ejecutivos as $ejecutivo)
        <option
            value="{{ route('admin.leads.index', array_merge(request()->except($parametro), [$parametro => $ejecutivo->id])) }}"
            @selected($ejecutivoElegido === (int) $ejecutivo->id)
        >
            {{ $ejecutivo->name }}
        </option>
    @endforeach
</select>
