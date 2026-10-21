@php
    $options = $attribute->lookup_type
        ? app('Webkul\Attribute\Repositories\AttributeRepository')->getLookUpOptions($attribute->lookup_type)
        : $attribute->options()->orderBy('sort_order')->get();

    // Un lookup acotado (p. ej. `asesores`) puede no incluir el valor actual:
    // un lead viejo cuyo propietario ya no es asesor. Sin esto el campo se
    // vería vacío y, al guardar, se perdería el propietario sin que nadie lo
    // haya elegido.
    $valorActual = old($attribute->code) ?? ($value ?? null);

    if (
        $attribute->lookup_type
        && filled($valorActual)
        && $options instanceof \Illuminate\Support\Collection
        && ! $options->contains('id', $valorActual)
    ) {
        $actual = app('Webkul\Attribute\Repositories\AttributeRepository')->getLookUpEntity($attribute->lookup_type, $valorActual);

        if ($actual) {
            $options->prepend($actual);
        }
    }
    // El asesor de un lead solo lo cambian Administrador y Supervisores (spec
    // tablero-por-asesor, D15). El servidor ya lo ignora; esto es para que el
    // asesor no vea un campo editable que no hace nada.
    $soloLectura = $attribute->code === 'user_id'
        && $attribute->entity_type === 'leads'
        && ! app(\Webkul\Admin\Helpers\Alcance::class)->veTotal();
@endphp

<x-admin::form.control-group.control
    type="select"
    id="{{ $attribute->code }}"
    name="{{ $attribute->code }}"
    rules="{{ $validations }}"
    :label="$attribute->name"
    :placeholder="$attribute->name"
    :value="old($attribute->code) ?? $value"
    :disabled="$soloLectura"
>
    @foreach ($options as $option)
        <option value="{{ $option->id }}">
            {{ $option->name }}
        </option>
    @endforeach
</x-admin::form.control-group.control>