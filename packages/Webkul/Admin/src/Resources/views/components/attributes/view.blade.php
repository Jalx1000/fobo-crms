@props([
    'customAttributes' => [],
    'entity'           => null,
    'allowEdit'        => false,
    'url'              => null,
])

<div class="flex flex-col gap-1">
    @foreach ($customAttributes as $attribute)
        @if ($attribute->code == 'combos_productos')
            @continue
        @endif

        @if (view()->exists($typeView = 'admin::components.attributes.view.' . $attribute->type))
            <div class="grid grid-cols-[1fr_2fr] items-center gap-1">
                <div class="label dark:text-white">{{ $attribute->name }}</div>

                <div class="font-medium dark:text-white">
                    @php
                        $value = isset($entity) ? $entity[$attribute->code] : null;

                        if (in_array($attribute->code, ['lead_value', 'price']) && is_numeric($value)) {
                            $value = number_format((float) $value, 2, '.', '');
                        }

                        if ($attribute->code == 'combos_productos_cantidad' && !empty($value)) {
                            try {
                                $combos = json_decode($value, true);
                                if (is_array($combos)) {
                                    $value = collect($combos)->map(function($item) {
                                        return $item['name'] . ' (' . $item['qty'] . ')';
                                    })->implode(', ');
                                }
                            } catch (\Exception $e) {
                                // Keep original value if error
                            }
                        }
                    @endphp

                    @include ($typeView, [
                        'attribute' => $attribute,
                        'value'     => $value,
                        'allowEdit' => $allowEdit,
                        'url'       => $url,
                    ])

                    {{-- La moneda del valor del lead, al lado y no dentro.

                         Este campo se edita en línea: si la moneda formara parte del
                         valor, al editarlo se mandaría "BOB 1700000.00" como importe.
                         Los leads anteriores al campo muestran la moneda por defecto,
                         que es la que el sistema asume al crearlos. --}}
                    @if ($attribute->code === 'lead_value' && isset($entity) && $entity instanceof \Webkul\Lead\Contracts\Lead)
                        <span class="mt-0.5 block text-xs font-normal text-gray-500 dark:text-gray-400">
                            {{ $entity->lead_value_moneda ?: config('inmobiliaria.inmuebles.moneda_por_defecto', 'USD') }}
                        </span>
                    @endif
                </div>
            </div>
        @endif
    @endforeach
</div>