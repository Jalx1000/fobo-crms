@php
    $puedeAsignar = app(\Webkul\Core\Services\AsignacionDeAsesor::class)->puede();
@endphp

@foreach ($customAttributes as $attribute)
    @if ($attribute->code == 'combos_productos')
        @continue
    @endif

    {{-- El asesor sólo lo elige quien puede asignar: el resto lo ve y lo manda como está. --}}
    @if (
        $attribute->code == 'user_id'
        && in_array($attribute->entity_type, ['leads', 'persons'])
        && ! $puedeAsignar
    )
        @php
            $asesor = isset($entity) ? $entity['user_id'] : null;
        @endphp

        <x-admin::form.control-group class="mb-2.5 w-full">
            <x-admin::form.control-group.label>
                {{ $attribute->name }}
            </x-admin::form.control-group.label>

            <p class="flex min-h-[39px] w-full items-center rounded-md border bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                {{ $asesor ? app('Webkul\Attribute\Repositories\AttributeRepository')->getLookUpEntity($attribute->lookup_type, $asesor)?->name : '--' }}
            </p>

            @if ($asesor)
                <input type="hidden" name="user_id" value="{{ $asesor }}" />
            @endif
        </x-admin::form.control-group>

        @continue
    @endif

    @php
        $validations = [];

        if ($attribute->is_required) {
            $validations[] = 'required';
        }

        if ($attribute->type == 'price') {
            $validations[] = 'decimal';
        }

        $validations[] = $attribute->validation;

        $validations = implode('|', array_filter($validations));
    @endphp

    <x-admin::form.control-group class="mb-2.5 w-full">
        <x-admin::form.control-group.label
            for="{{ $attribute->code }}"
            :class="$attribute->is_required ? 'required' : ''"
        >
            {{ $attribute->name }}

            @if ($attribute->type == 'price')
                <span class="currency-code">({{ core()->currencySymbol(config('app.currency')) }})</span>
            @endif
        </x-admin::form.control-group.label>

        @if (isset($attribute))
            @php
                $val = isset($entity) ? $entity[$attribute->code] : null;

                if (in_array($attribute->code, ['lead_value', 'price']) && is_numeric($val)) {
                    $val = number_format((float) $val, 2, '.', '');
                }
            @endphp

            <x-admin::attributes.edit.index
                :attribute="$attribute"
                :validations="$validations"
                :value="$val"
            />
        @endif

        <x-admin::form.control-group.error :control-name="$attribute->code" />
    </x-admin::form.control-group>
@endforeach