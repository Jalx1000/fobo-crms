@props([
    'customAttributes' => [],
    'entity'           => null,
    'allowEdit'        => false,
    'url'              => null,
])

@php
    $puedeAsignar = app(\Webkul\Core\Services\AsignacionDeAsesor::class)->puede();
@endphp

<div class="flex flex-col gap-1">
    @foreach ($customAttributes as $attribute)
        @if ($attribute->code == 'combos_productos')
            @continue
        @endif

        @if (view()->exists($typeView = 'admin::components.attributes.view.' . $attribute->type))
            {{-- Celular: el nombre del dato chico arriba y el valor debajo; en dos columnas los nombres se partían. --}}
            <div class="grid grid-cols-[1fr_2fr] items-center gap-1 max-lg:grid-cols-1 max-lg:gap-0.5 max-lg:border-b max-lg:border-gray-100 max-lg:py-2 max-lg:last:border-b-0 dark:max-lg:border-gray-800">
                <div class="label dark:text-white max-lg:text-xs max-lg:text-gray-500 dark:max-lg:text-gray-400">{{ $attribute->name }}</div>

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
                        'allowEdit' => $allowEdit && (
                            $attribute->code != 'user_id'
                            || ! in_array($attribute->entity_type, ['leads', 'persons'])
                            || $puedeAsignar
                        ),
                        'url'       => $url,
                    ])
                </div>
            </div>
        @endif
    @endforeach
</div>