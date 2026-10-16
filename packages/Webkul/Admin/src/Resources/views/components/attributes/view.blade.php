@props([
    'customAttributes' => [],
    'entity'           => null,
    'allowEdit'        => false,
    'url'              => null,
    // Códigos que se muestran pero no se editan en línea (p. ej. el asesor
    // para un ejecutivo, spec tablero-por-asesor D13). El servidor igual los descarta.
    'readOnly'         => [],
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
                        'allowEdit' => $allowEdit && ! in_array($attribute->code, $readOnly, true),
                        'url'       => $url,
                    ])
                </div>
            </div>
        @endif
    @endforeach
</div>