{{--
    Formulario compartido por crear y editar.

    El período es un desplegable y no un `input type=month`: el componente de
    formulario del CRM no soporta ese tipo y el campo no se dibujaba. Además,
    con un select no se puede cargar un período con formato inválido.
--}}
<div class="flex flex-col gap-4 p-4">
    <x-admin::form.control-group>
        <x-admin::form.control-group.label>Período</x-admin::form.control-group.label>

        <x-admin::form.control-group.control
            type="select"
            name="periodo"
            rules="required"
            :value="old('periodo') ?? ($meta->periodo ?? now()->format('Y-m'))"
            label="Período"
        >
            @foreach ($periodos as $valor => $etiqueta)
                <option value="{{ $valor }}">{{ ucfirst($etiqueta) }}</option>
            @endforeach
        </x-admin::form.control-group.control>

        <x-admin::form.control-group.error control-name="periodo" />
    </x-admin::form.control-group>

    <x-admin::form.control-group>
        <x-admin::form.control-group.label>Aplica a</x-admin::form.control-group.label>

        <x-admin::form.control-group.control
            type="select"
            name="user_id"
            :value="old('user_id') ?? ($meta->user_id ?? '')"
            label="Aplica a"
        >
            <option value="">Todo el equipo</option>

            @foreach ($usuarios as $usuario)
                <option value="{{ $usuario->id }}">{{ $usuario->name }}</option>
            @endforeach
        </x-admin::form.control-group.control>

        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Dejalo en «Todo el equipo» para la meta global. Las dos conviven.
        </p>
    </x-admin::form.control-group>

    <x-admin::form.control-group>
        <x-admin::form.control-group.label>Métrica</x-admin::form.control-group.label>

        <x-admin::form.control-group.control
            type="select"
            name="metrica"
            rules="required"
            :value="old('metrica') ?? ($meta->metrica ?? 'ventas')"
            label="Métrica"
        >
            @foreach ($metricas as $clave => $def)
                <option value="{{ $clave }}">{{ $def['label'] }}</option>
            @endforeach
        </x-admin::form.control-group.control>

        <x-admin::form.control-group.error control-name="metrica" />
    </x-admin::form.control-group>

    <x-admin::form.control-group>
        <x-admin::form.control-group.label>Objetivo</x-admin::form.control-group.label>

        <x-admin::form.control-group.control
            type="text"
            name="objetivo"
            rules="required|decimal:0,2"
            :value="old('objetivo') ?? ($meta->objetivo ?? '')"
            label="Objetivo"
        />

        <x-admin::form.control-group.error control-name="objetivo" />
    </x-admin::form.control-group>

    <x-admin::form.control-group>
        <x-admin::form.control-group.label>Moneda</x-admin::form.control-group.label>

        <x-admin::form.control-group.control
            type="select"
            name="moneda"
            :value="old('moneda') ?? ($meta->moneda ?? '')"
            label="Moneda"
        >
            <option value="">— (solo para metas de monto)</option>
            <option value="USD">USD</option>
            <option value="BOB">BOB</option>
        </x-admin::form.control-group.control>

        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Obligatoria en metas de monto: la cartera mezcla USD y Bs, y un
            objetivo sin moneda es ambiguo por un factor de siete.
        </p>

        <x-admin::form.control-group.error control-name="moneda" />
    </x-admin::form.control-group>
</div>
