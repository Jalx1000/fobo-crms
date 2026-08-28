{{--
    Selector de moneda del cierre.

    Se inyecta en el modal de cambio de etapa por el punto de extensión que el
    core ya expone, para no editar su vista. Vive dentro del mismo componente
    Vue, así que `nextStage` está en alcance.
--}}
<template v-if="nextStage.code == 'won'">
    <x-admin::form.control-group>
        <x-admin::form.control-group.label>
            Moneda del cierre
        </x-admin::form.control-group.label>

        <select
            name="lead_value_moneda"
            v-model="nextStage.lead_value_moneda"
            class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
        >
            @foreach (config('inmobiliaria.inmuebles.monedas') as $moneda)
                <option value="{{ $moneda }}" @selected($lead->lead_value_moneda === $moneda || (! $lead->lead_value_moneda && $moneda === config('inmobiliaria.inmuebles.moneda_por_defecto')))>
                    {{ $moneda }}
                </option>
            @endforeach
        </select>

        <p class="mt-1 text-xs text-gray-500">
            El valor se guarda en esta moneda. El sistema no convierte entre dólares y bolivianos.
        </p>
    </x-admin::form.control-group>
</template>
