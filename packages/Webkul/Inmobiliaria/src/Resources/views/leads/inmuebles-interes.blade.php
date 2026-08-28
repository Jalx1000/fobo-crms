@php
    $rutaMedia = fn ($inmueble) => optional($inmueble->medias->firstWhere('tipo', 'foto'))->id;
@endphp

<div class="mt-4 flex flex-col gap-2 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
    <div class="flex items-center justify-between">
        <p class="text-base font-semibold dark:text-white">
            Inmuebles de interés
            <span class="font-normal text-gray-500">({{ $inmuebles->count() }})</span>
        </p>
    </div>

    <div class="flex flex-col gap-2">
        @foreach ($inmuebles as $inmueble)
            <div class="flex items-center gap-3 rounded border border-gray-200 p-2 dark:border-gray-800">
                @if ($id = $rutaMedia($inmueble))
                    <img src="{{ route('admin.inmuebles.medias.archivo', $id) }}"
                         alt="{{ $inmueble->titulo }}"
                         class="h-14 w-20 shrink-0 rounded object-cover" />
                @else
                    <div class="flex h-14 w-20 shrink-0 items-center justify-center rounded bg-gray-100 text-[10px] text-gray-400 dark:bg-gray-800">
                        Sin fotos
                    </div>
                @endif

                <div class="min-w-0 flex-1">
                    <a href="{{ route('admin.inmuebles.edit', $inmueble->id) }}"
                       class="block truncate text-sm font-medium text-brandColor hover:underline">
                        {{ $inmueble->codigo }} — {{ $inmueble->titulo }}
                    </a>
                    <p class="truncate text-xs text-gray-500">
                        {{ $inmueble->zona }}@if ($inmueble->barrio), {{ $inmueble->barrio }}@endif
                        · {{ $inmueble->moneda }} {{ number_format((float) $inmueble->precio, 0, ',', '.') }}
                        @if ($inmueble->origen === 'ofrecido')
                            <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500 dark:bg-gray-800">
                                sugerido por el agente
                            </span>
                        @endif
                    </p>
                </div>

                <span class="shrink-0 text-xs {{ $inmueble->estado === 'disponible' ? 'text-emerald-600' : 'text-amber-600' }}">
                    {{ ucfirst($inmueble->estado) }}
                </span>
            </div>
        @endforeach
    </div>
</div>
