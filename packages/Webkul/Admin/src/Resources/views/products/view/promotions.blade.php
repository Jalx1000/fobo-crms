{!! view_render_event('admin.products.view.promotions.before', ['product' => $product]) !!}

<!-- Service Promotions Component -->
<v-service-promotions></v-service-promotions>

{!! view_render_event('admin.products.view.promotions.after', ['product' => $product]) !!}

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-service-promotions-template"
    >
        <div class="flex flex-col gap-4 p-4">
            <p class="text-sm text-gray-500 dark:text-gray-300">
                {{ __('Promoción con vigencia: precio promocional o % de descuento. Opcionalmente para una zona; si no, aplica a todo el servicio.') }}
            </p>

            <div class="block w-full overflow-x-auto">
                <x-admin::table>
                    <x-admin::table.thead>
                        <x-admin::table.thead.tr>
                            <x-admin::table.th>{{ __('Nombre') }}</x-admin::table.th>
                            <x-admin::table.th>{{ __('Zona') }}</x-admin::table.th>
                            <x-admin::table.th class="!w-[110px]">{{ __('Precio') }}</x-admin::table.th>
                            <x-admin::table.th class="!w-[90px]">{{ __('% desc.') }}</x-admin::table.th>
                            <x-admin::table.th class="!w-[150px]">{{ __('Desde') }}</x-admin::table.th>
                            <x-admin::table.th class="!w-[150px]">{{ __('Hasta') }}</x-admin::table.th>
                            <x-admin::table.th class="!w-[70px]">{{ __('Activa') }}</x-admin::table.th>
                            <x-admin::table.th class="!w-[50px]"></x-admin::table.th>
                        </x-admin::table.thead.tr>
                    </x-admin::table.thead>

                    <x-admin::table.tbody>
                        <x-admin::table.tbody.tr
                            v-for="(promo, index) in promotions"
                            ::key="index"
                        >
                            <x-admin::table.td class="!px-2">
                                <input type="text" v-model="promo.name" placeholder="Promo octubre"
                                    class="w-full rounded-md border border-gray-200 px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2">
                                <select v-model="promo.service_zone_id"
                                    class="w-full rounded-md border border-gray-200 px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                    <option :value="null">{{ __('Todas') }}</option>
                                    <option v-for="zone in zones" :value="zone.id">@{{ zone.name }}</option>
                                </select>
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2">
                                <input type="number" min="0" step="0.01" v-model="promo.price"
                                    class="w-full rounded-md border border-gray-200 px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2">
                                <input type="number" min="0" max="100" step="0.01" v-model="promo.discount_percent"
                                    class="w-full rounded-md border border-gray-200 px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2">
                                <input type="date" v-model="promo.valid_from"
                                    class="w-full rounded-md border border-gray-200 px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2">
                                <input type="date" v-model="promo.valid_to"
                                    class="w-full rounded-md border border-gray-200 px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" />
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2 text-center">
                                <input type="checkbox" v-model="promo.is_active" class="cursor-pointer" />
                            </x-admin::table.td>

                            <x-admin::table.td class="!px-2">
                                <i @click="removePromotion(index)" class="icon-delete cursor-pointer text-2xl"></i>
                            </x-admin::table.td>
                        </x-admin::table.tbody.tr>
                    </x-admin::table.tbody>
                </x-admin::table>
            </div>

            <div class="flex items-center justify-between">
                <button type="button" class="flex max-w-max items-center gap-2 text-brandColor" @click="addPromotion">
                    <i class="icon-add text-md !text-brandColor"></i>
                    {{ __('Agregar promoción') }}
                </button>

                <button type="button" class="primary-button" @click="save" :disabled="saving">
                    {{ __('Guardar promociones') }}
                </button>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-service-promotions', {
            template: '#v-service-promotions-template',

            data() {
                return {
                    promotions: [],
                    zones: [],
                    saving: false,
                };
            },

            mounted() {
                this.fetchPromotions();
            },

            methods: {
                fetchPromotions() {
                    this.$axios.get("{{ route('admin.products.promotions.index', $product->id) }}")
                        .then(response => {
                            this.zones = response.data.zones;

                            this.promotions = response.data.data.map(promo => ({
                                ...promo,
                                is_active:  !! promo.is_active,
                                valid_from: promo.valid_from ? String(promo.valid_from).substring(0, 10) : null,
                                valid_to:   promo.valid_to ? String(promo.valid_to).substring(0, 10) : null,
                            }));
                        })
                        .catch(error => console.log(error));
                },

                addPromotion() {
                    this.promotions.push({
                        id: null,
                        service_zone_id: null,
                        name: '',
                        price: null,
                        discount_percent: null,
                        valid_from: null,
                        valid_to: null,
                        is_active: true,
                    });
                },

                removePromotion(index) {
                    this.promotions.splice(index, 1);
                },

                save() {
                    this.saving = true;

                    this.$axios.post("{{ route('admin.products.promotions.sync', $product->id) }}", { promotions: this.promotions })
                        .then(response => {
                            this.fetchPromotions();
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
