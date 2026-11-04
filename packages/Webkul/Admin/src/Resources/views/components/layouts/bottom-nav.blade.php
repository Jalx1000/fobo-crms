{{--
    Barra inferior del celular (debajo de lg): los módulos más usados a un
    toque y «Más», que abre el menú lateral con todos.

    Sale del menú real (menu()->getItems), así respeta los permisos de cada
    usuario: toma las claves de `admin.mobile_nav` que existan y, si faltan,
    completa con los primeros módulos del menú.
--}}
@php
    // Odontoking: la recepción vive en WhatsApp, las citas (Pedidos), los
    // pacientes (Contactos) y la agenda (Actividades).
    $claves = (array) config('admin.mobile_nav', ['whatsapp', 'leads', 'contacts', 'activities']);

    $menu = menu()->getItems('admin');

    $fijos = collect($claves)
        ->map(fn ($clave) => $menu->first(fn ($item) => $item->getKey() === $clave))
        ->filter();

    $fijos = $fijos
        ->concat($menu->reject(fn ($item) => $fijos->contains($item) || in_array($item->getKey(), ['settings', 'configuration'])))
        ->take(4)
        ->values();
@endphp

<nav
    aria-label="@lang('admin::app.layouts.mobile-nav.title')"
    class="fixed inset-x-0 bottom-0 z-[10000] grid grid-cols-5 [.chat-abierto_&]:hidden border-t border-gray-200 bg-white pb-[env(safe-area-inset-bottom)] dark:border-gray-800 dark:bg-gray-900 lg:hidden"
>
    @foreach ($fijos as $item)
        @php
            $activo = $item->isActive();
        @endphp

        <a
            href="{{ $item->getUrl() }}"
            @class([
                'flex h-14 flex-col items-center justify-center gap-0.5 text-[11px] transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor',
                'font-semibold text-brandColor' => $activo,
                'text-gray-600 dark:text-gray-400' => ! $activo,
            ])
            @if ($activo) aria-current="page" @endif
        >
            <span class="{{ $item->getIcon() }} text-2xl" aria-hidden="true"></span>

            <span class="max-w-full truncate px-1">{{ $item->getName() }}</span>
        </a>
    @endforeach

    <button
        type="button"
        class="flex h-14 flex-col items-center justify-center gap-0.5 text-[11px] text-gray-600 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brandColor dark:text-gray-400"
        @click="$emitter.emit('mobile-nav:more')"
    >
        <span class="icon-menu text-2xl" aria-hidden="true"></span>

        <span>@lang('admin::app.layouts.mobile-nav.more')</span>
    </button>
</nav>
