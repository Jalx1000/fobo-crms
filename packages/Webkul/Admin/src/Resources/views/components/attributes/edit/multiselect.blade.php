@php
    $options = $attribute->lookup_type
        ? app('Webkul\Attribute\Repositories\AttributeRepository')->getLookUpOptions($attribute->lookup_type)
        : $attribute->options()->orderBy('sort_order')->get();

    $selectedOption = old($attribute->code) ?: $value;

    $selectedIds = is_array($selectedOption)
        ? $selectedOption
        : (strlen((string) $selectedOption) ? explode(',', (string) $selectedOption) : []);

    $optionsForVue = collect($options)->map(fn ($o) => ['id' => (string) $o->id, 'name' => $o->name])->values();
@endphp

<v-multiselect-chips
    name="{{ $attribute->code }}"
    rules="{{ $validations }}"
    label="{{ $attribute->name }}"
    placeholder="{{ $attribute->name }}"
    :options='@json($optionsForVue)'
    :selected='@json(array_values(array_map("strval", $selectedIds)))'
></v-multiselect-chips>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-multiselect-chips-template"
    >
        <div class="relative w-full" ref="root">
            <!-- Caja con chips -->
            <div
                class="flex min-h-[39px] w-full cursor-text flex-wrap items-center gap-1 rounded-md border border-gray-200 px-2.5 py-1.5 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                @click="open = true"
            >
                <span
                    v-for="opt in selectedOptions"
                    :key="'chip-' + opt.id"
                    class="flex items-center gap-1 rounded-md bg-slate-100 py-0.5 pl-2 dark:bg-gray-800 dark:text-white"
                >
                    <input type="hidden" :name="name + '[]'" :value="opt.id" />

                    <span class="max-w-[160px] truncate">@{{ opt.name }}</span>

                    <span
                        class="icon-cross-large cursor-pointer p-0.5 text-lg text-gray-500 hover:text-red-600"
                        @click.stop="remove(opt)"
                    ></span>
                </span>

                <span v-if="! selectedOptions.length" class="text-gray-400">@{{ placeholder }}</span>
            </div>

            <!-- Dropdown de opciones disponibles -->
            <div
                v-if="open && available.length"
                class="absolute z-10 mt-1 w-full origin-top rounded-lg border bg-white shadow-lg dark:border-gray-800 dark:bg-gray-800"
            >
                <ul class="max-h-40 overflow-y-auto p-0.5">
                    <li
                        v-for="opt in available"
                        :key="'opt-' + opt.id"
                        class="cursor-pointer rounded px-4 py-2 text-gray-800 transition-colors hover:bg-blue-100 dark:text-white dark:hover:bg-gray-950"
                        @click="add(opt)"
                    >
                        @{{ opt.name }}
                    </li>
                </ul>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-multiselect-chips', {
            template: '#v-multiselect-chips-template',

            props: {
                name:        { type: String, required: true },
                options:     { type: Array, default: () => [] },
                selected:    { type: Array, default: () => [] },
                placeholder: { type: String, default: '' },
                rules:       { type: String, default: '' },
                label:       { type: String, default: '' },
            },

            data() {
                return {
                    open: false,
                    selectedIds: (this.selected ?? []).map((id) => String(id)),
                };
            },

            computed: {
                selectedOptions() {
                    return this.options.filter((o) => this.selectedIds.includes(String(o.id)));
                },
                available() {
                    return this.options.filter((o) => ! this.selectedIds.includes(String(o.id)));
                },
            },

            methods: {
                add(opt) {
                    if (! this.selectedIds.includes(String(opt.id))) {
                        this.selectedIds.push(String(opt.id));
                    }
                    this.open = false;
                },
                remove(opt) {
                    this.selectedIds = this.selectedIds.filter((id) => id !== String(opt.id));
                },
                /**
                 * Agrega opciones por nombre desde fuera de Vue (autocompletar de SMD):
                 * window.emitter.emit('multiselect:agregar', { name, nombres, listo }).
                 * Sin tildes ni mayúsculas («Membresía Odontoking» = «Membresia
                 * Odontoking»); si no hay igual exacto, una que contenga a la otra.
                 * `listo(agregados, noEncontrados)` corre con el DOM ya actualizado.
                 */
                agregarPorNombre({ name, nombres = [], listo }) {
                    if (name !== this.name) {
                        return;
                    }

                    const normalizar = (texto) => (texto || '')
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .toLowerCase()
                        .replace(/\s+/g, ' ')
                        .trim();

                    const agregados = [];
                    const noEncontrados = [];

                    nombres.forEach((nombre) => {
                        const buscado = normalizar(nombre);

                        const opcion = this.options.find((o) => normalizar(o.name) === buscado)
                            ?? this.options.find((o) => {
                                const actual = normalizar(o.name);

                                return buscado.length >= 4 && actual.length >= 4
                                    && (actual.includes(buscado) || buscado.includes(actual));
                            });

                        if (! opcion) {
                            noEncontrados.push(nombre);

                            return;
                        }

                        if (! this.selectedIds.includes(String(opcion.id))) {
                            this.selectedIds.push(String(opcion.id));
                        }

                        agregados.push(opcion.name);
                    });

                    this.$nextTick(() => listo?.(agregados, noEncontrados));
                },

                onClickOutside(e) {
                    if (this.$refs.root && ! this.$refs.root.contains(e.target)) {
                        this.open = false;
                    }
                },
            },

            mounted() {
                window.addEventListener('click', this.onClickOutside);

                this.$emitter.on('multiselect:agregar', this.agregarPorNombre);
            },

            beforeUnmount() {
                window.removeEventListener('click', this.onClickOutside);

                this.$emitter.off('multiselect:agregar', this.agregarPorNombre);
            },
        });
    </script>
@endPushOnce
