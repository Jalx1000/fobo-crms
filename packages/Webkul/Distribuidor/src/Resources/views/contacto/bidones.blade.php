@php
    /*
     * El servicio se resuelve acá y no llega por parámetro porque el evento de render
     * de Krayin sólo pasa lo que la ficha le dio —$person— y addTemplate() no acepta
     * datos extra. Es una consulta agregada por ficha, así que no hay N+1.
     */
    $bidones = app(\Webkul\Distribuidor\Services\Bidones::class)->resumenDe((int) $person->id);
@endphp

{{--
    Sólo si el cliente tuvo movimientos. Una tarjeta que dice "0 bidones" en cada
    contacto que nunca compró agua es ruido en una ficha que ya es larga.
--}}
@if ($bidones['movimientos'] > 0)
    <div class="mt-4 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
            <p class="text-base font-semibold text-gray-800 dark:text-white">Bidones</p>

            {{--
                El saldo es el dato de la tarjeta, así que va grande y con color: ámbar
                cuando el cliente tiene envases nuestros, verde cuando está en cero.
                Negativo se muestra tal cual, en rojo: significa que se retiraron más de
                los registrados —envases de antes del control, o un movimiento mal
                cargado— y taparlo esconde el error justo cuando hay que verlo.
            --}}
            <span class="rounded-full px-3 py-1 text-base font-bold
                @if ($bidones['saldo'] > 0) bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200
                @elseif ($bidones['saldo'] < 0) bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200
                @else bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200 @endif">
                {{ $bidones['saldo'] }}
                {{ abs($bidones['saldo']) === 1 ? 'sin devolver' : 'sin devolver' }}
            </span>
        </div>

        <div class="px-4 py-3">
            <div class="flex items-center justify-between gap-3 text-base">
                <span class="text-gray-600 dark:text-gray-300">Entregados</span>
                <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $bidones['entregados'] }}</span>
            </div>

            <div class="mt-1 flex items-center justify-between gap-3 text-base">
                <span class="text-gray-600 dark:text-gray-300">Devueltos</span>
                <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $bidones['retirados'] }}</span>
            </div>

            {{--
                Desde cuándo. "Tiene 3 bidones" sin fecha no dice si se los dejaron ayer
                o hace cuatro meses, y esa diferencia es la que decide si hay que ir a
                buscarlos.
            --}}
            @if ($bidones['ultimo'])
                <div class="mt-2 border-t border-gray-100 pt-2 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-400">
                    Último movimiento: {{ \Carbon\Carbon::parse($bidones['ultimo'])->format('d/m/Y') }}
                </div>
            @endif
        </div>
    </div>
@endif
