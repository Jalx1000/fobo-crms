@php
    $portada = fn ($inmueble) => optional($inmueble->portada())->id;
    $etiquetaTipo = fn ($inmueble) => config("inmobiliaria.inmuebles.tipos.{$inmueble->tipo}", ucfirst($inmueble->tipo));
@endphp

<div class="flex flex-1 flex-col gap-3 p-3">
    @forelse ($inmuebles as $inmueble)
        <div class="flex gap-4 rounded-lg border border-gray-200 p-3 transition hover:border-brandColor dark:border-gray-800">
            {{-- Portada --}}
            <a href="{{ route('admin.inmuebles.edit', $inmueble->id) }}"
               class="shrink-0"
               aria-label="Abrir {{ $inmueble->codigo }}">
                @if ($id = $portada($inmueble))
                    <img src="{{ route('admin.inmuebles.medias.archivo', $id) }}"
                         alt="{{ $inmueble->titulo }}"
                         class="h-24 w-32 rounded-md object-cover" />
                @else
                    <div class="flex h-24 w-32 flex-col items-center justify-center gap-1 rounded-md border border-dashed border-gray-300 text-[11px] text-gray-400 dark:border-gray-700">
                        <span class="icon-image text-lg"></span>
                        Sin fotos
                    </div>
                @endif
            </a>

            {{-- Datos --}}
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.inmuebles.edit', $inmueble->id) }}"
                       class="font-semibold text-brandColor hover:underline">
                        {{ $inmueble->codigo }}
                    </a>

                    <span class="rounded-full px-2 py-0.5 text-[11px] font-medium
                        {{ $inmueble->estado === 'disponible'
                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                            : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' }}">
                        {{ ucfirst($inmueble->estado) }}
                    </span>

                    @if ($inmueble->origen === 'ofrecido')
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                            Sugerido por el agente
                        </span>
                    @endif
                </div>

                <p class="truncate text-sm text-gray-800 dark:text-gray-200">{{ $inmueble->titulo }}</p>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $etiquetaTipo($inmueble) }} ·
                    {{ $inmueble->zona }}@if ($inmueble->barrio), {{ $inmueble->barrio }} @endif

                    @if ($inmueble->dormitorios)
                        · {{ $inmueble->dormitorios }} dorm.
                    @endif

                    @if ($inmueble->m2_terreno)
                        · {{ (int) $inmueble->m2_terreno }} m² terreno
                    @endif
                </p>
            </div>

            {{-- Precio: siempre en la moneda guardada, nunca convertido --}}
            <div class="shrink-0 text-right">
                <p class="whitespace-nowrap font-semibold tabular-nums dark:text-white">
                    {{ $inmueble->moneda }} {{ number_format((float) $inmueble->precio, 0, ',', '.') }}
                </p>

                @if ($inmueble->precio_anterior)
                    <p class="whitespace-nowrap text-xs text-gray-400 line-through">
                        {{ number_format((float) $inmueble->precio_anterior, 0, ',', '.') }}
                    </p>
                @endif

                @if ($inmueble->modalidad_cambio)
                    <p class="mt-0.5 text-[11px] text-gray-500">
                        {{ ['solo_usd' => 'Solo dólares', 'tc_oficial' => 'T/C oficial BCB', 'tc_paralelo' => 'Cambio paralelo'][$inmueble->modalidad_cambio] }}
                    </p>
                @endif
            </div>
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-gray-300 px-4 py-10 text-center dark:border-gray-700">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Este lead todavía no tiene inmuebles de interés.
            </p>
            <p class="mt-1 text-xs text-gray-400">
                Se registran solos cuando el agente crea la solicitud con los códigos que consultó el cliente.
            </p>
        </div>
    @endforelse
</div>
