@php
    $options = $attribute->lookup_type
        ? app('Webkul\Attribute\Repositories\AttributeRepository')->getLookUpOptions($attribute->lookup_type)
        : $attribute->options()->orderBy('sort_order')->get();

    $selectedOption = old($attribute->code) ?: $value;

    /*
    | explode(',', '') devuelve [''] - un array con una cadena vacía, no un array
    | vacío. Sin filtrar, el multiselect dibuja un chip sin texto para ese '' y,
    | peor, lo manda en el submit como una opción más.
    |
    | array_values() no es cosmético: array_filter conserva las claves, y un array
    | con huecos se serializa como objeto JSON ({"1":"10"}) en vez de lista.
    */
    $selectedOptions = array_values(array_filter(
        is_array($selectedOption) ? $selectedOption : explode(',', (string) $selectedOption),
        fn ($opcion) => $opcion !== '' && $opcion !== null,
    ));
@endphp

<v-field
    name="{{ $attribute->code }}[]"
    rules="{{ $validations }}"
    label="{{ $attribute->name }}"
    v-slot="{ field, errors, setValue }"
>
    <v-multiselect
        :items="{{ json_encode($options) }}"
        :model-value="field.value || {{ json_encode($selectedOptions) }}"
        @update:model-value="setValue($event)"
        placeholder="{{ $attribute->name }}"
        name="{{ $attribute->code }}[]"
    ></v-multiselect>

    <p
        class="mt-1 text-xs text-red-600"
        v-if="errors.length"
    >
        @{{ errors[0] }}
    </p>
</v-field>