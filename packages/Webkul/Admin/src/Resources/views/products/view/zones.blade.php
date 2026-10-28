{!! view_render_event('admin.products.view.zones.before', ['product' => $product]) !!}

<!-- Service Zones Component -->
<v-service-zones></v-service-zones>

{!! view_render_event('admin.products.view.zones.after', ['product' => $product]) !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-service-zones-template"
    >
        <div class="flex flex-col gap-4 p-4">
            <p class="text-sm text-gray-500 dark:text-gray-300">
                {{ __('Cada zona es una variante elegible del servicio, con su propia duración y precio.') }}
            </p>

            <div class="block w-full overflow-x-auto">
                <x-admin::table>
                    <x-admin::table.thead>
                        <x-admin::table.thead.tr>
                            <x-admin::table.th>{{ __('Zona') }}</x-admin::table.th>
                            <x-admin::table.th class="!w-[160px]">{{ __('Duración (min)') }}</x-admin::table.th>
                            <x-admin::table.th class="!w-[160px]">{{ __('Precio') }}</x-admin::table.th>
                            <x-admin::table.th class="!w-[60px]"></x-admin::table.th>
                        </x-admin::table.thead.tr>
                    </x-admin::table.thead>

                    <x-admin::table.tbody>
                        <x-admin::table.tbody.tr
                            v-for="(zone, index) in zones"
                            ::key="index"
                        >
                            <x-admin::table.td class="!px-2">
                                <input
                                    type="text"
                                    v-model="zone.name"
                                    placeholder="Axilas"
                                    class="w-full rounded-md border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                />
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2">
                                <input
                                    type="number"
                                    min="1"
                                    max="480"
                                    v-model="zone.duration_minutes"
                                    class="w-full rounded-md border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                />
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    v-model="zone.price"
                                    class="w-full rounded-md border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                />
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2">
                                <i
                                    @click="removeZone(index)"
                                    class="icon-delete cursor-pointer text-2xl"
                                ></i>
                            </x-admin::table.td>
                        </x-admin::table.tbody.tr>
                    </x-admin::table.tbody>
                </x-admin::table>
            </div>

            <div class="flex items-center justify-between">
                <button
                    type="button"
                    class="flex max-w-max items-center gap-2 text-brandColor"
                    @click="addZone"
                >
                    <i class="icon-add text-md !text-brandColor"></i>
                    {{ __('Agregar zona') }}
                </button>

                <button
                    type="button"
                    class="primary-button"
                    @click="save"
                    :disabled="saving"
                >
                    {{ __('Guardar zonas') }}
                </button>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-service-zones', {
            template: '#v-service-zones-template',

            data() {
                return {
                    zones: [],
                    saving: false,
                };
            },

            mounted() {
                this.fetchZones();
            },

            methods: {
                fetchZones() {
                    this.$axios.get("{{ route('admin.products.zones.index', $product->id) }}")
                        .then(response => {
                            this.zones = response.data.data;
                        })
                        .catch(error => console.log(error));
                },

                addZone() {
                    this.zones.push({ id: null, name: '', duration_minutes: 60, price: 0 });
                },

                removeZone(index) {
                    this.zones.splice(index, 1);
                },

                save() {
                    this.saving = true;

                    this.$axios.post("{{ route('admin.products.zones.sync', $product->id) }}", { zones: this.zones })
                        .then(response => {
                            this.fetchZones();
                            this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                        })
                        .catch(error => {
                            this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message ?? 'Error' });
                        })
                        .finally(() => { this.saving = false; });
                },
            },
        });
    </script>
@endPushOnce
