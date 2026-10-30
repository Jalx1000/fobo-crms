@props([
    'customAttributes' => [],
    'entity'           => null,
    'allowEdit'        => false,
    'url'              => null,
])

<div class="flex flex-col gap-1">
    @foreach ($customAttributes as $attribute)
        @if (view()->exists($typeView = 'admin::components.attributes.view.' . $attribute->type))
            {{-- Celular: el nombre del dato chico arriba y el valor debajo; en dos columnas los nombres se partían. --}}
            <div class="grid grid-cols-[1fr_2fr] items-center gap-1 max-lg:grid-cols-1 max-lg:gap-0.5 max-lg:border-b max-lg:border-gray-100 max-lg:py-2 max-lg:last:border-b-0 dark:max-lg:border-gray-800">
                <div class="label dark:text-white max-lg:text-xs max-lg:text-gray-500 dark:max-lg:text-gray-400">{{ $attribute->name }}</div>

                <div class="font-medium dark:text-white">
                    @include ($typeView, [
                        'attribute' => $attribute,
                        'value'     => isset($entity) ? $entity[$attribute->code] : null,
                        'allowEdit' => $allowEdit,
                        'url'       => $url,
                    ])
                </div>
            </div>
        @endif
    @endforeach
</div>