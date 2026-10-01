<x-admin::layouts>
    <x-slot:title>WhatsApp · {{ $person->name }}</x-slot>

    {{--
        El chat del cliente de un pedido.

        Reusa `whatsapp::inbox` tal cual, que es el mismo componente que se monta
        en la ficha del lead y del contacto: así el distribuidor ve exactamente el
        mismo hilo que el asesor, sin una segunda implementación que se desincronice.
    --}}
    <div class="mb-3">
        <a href="{{ route('admin.distribuidor.index') }}"
           class="flex min-h-[64px] w-full cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-gray-300 text-lg font-bold text-gray-700 transition-colors duration-200 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brandColor focus:ring-offset-2 motion-reduce:transition-none dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900 sm:w-auto sm:px-8">
            {{-- SVG y no "←": una flecha de texto la lee el lector de pantalla como símbolo suelto. --}}
            <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
            </svg>
            Volver al Punto
        </a>
    </div>

    <div class="mb-3 rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800/50">
        <div class="text-xl font-bold text-gray-800 dark:text-gray-100">{{ $person->name }}</div>
        <div class="text-base text-gray-500 dark:text-gray-400">Pedido #{{ $lead->id }}</div>
    </div>

    @include('whatsapp::inbox')
</x-admin::layouts>
