<!DOCTYPE html>

<html
    class="{{ request()->cookie('dark_mode') ? 'dark' : '' }}"
    lang="{{ app()->getLocale() }}"
    dir="{{ in_array(app()->getLocale(), ['fa', 'ar']) ? 'rtl' : 'ltr' }}"
>

<head>

    {!! view_render_event('admin.layout.head.before') !!}

    <title>{{ $title ?? '' }}</title>

    <meta charset="UTF-8">

    <meta
        http-equiv="X-UA-Compatible"
        content="IE=edge"
    >
    <meta
        http-equiv="content-language"
        content="{{ app()->getLocale() }}"
    >

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover"
    >
    <meta
        name="base-url"
        content="{{ url()->to('/') }}"
    >
    <meta
        name="currency"
        content="{{
            json_encode([
                'code'   => config('app.currency'),
                'symbol' => core()->currencySymbol(config('app.currency'))])
            }}
        "
    >

    @stack('meta')

    <x-admin::layouts.pwa />

    {{
        vite()->set(['src/Resources/assets/css/app.css', 'src/Resources/assets/js/app.js'])
    }}

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap"
        rel="stylesheet"
    />

    <link
        rel="preload"
        as="image"
        href="{{ url('cache/logo/bagisto.png') }}"
    >

    @if ($favicon = core()->getConfigData('general.design.admin_logo.favicon'))
        <link
            type="image/x-icon"
            href="{{ Storage::url($favicon) }}"
            rel="shortcut icon"
            sizes="16x16"
        >
    @else
        <link
            type="image/x-icon"
            href="{{ vite()->asset('images/favicon.ico') }}"
            rel="shortcut icon"
            sizes="16x16"
        />
    @endif

    @php
        $brandColor = core()->getConfigData('general.settings.menu_color.brand_color') ?? '#0E90D9';
    @endphp

    @stack('styles')

    <style>
        :root {
            --brand-color: {{ $brandColor }};
        }

        /* Global Multiselect Styles */
        .v-multiselect-container { min-width: 240px; position: relative; }
        .v-multiselect-input { 
            display: flex; align-items: center; justify-content: space-between; 
            border: 1px solid #e5e7eb; border-radius: 8px; padding: 6px 10px; 
            background: #fff; transition: all 0.2s; min-height: 40px; gap: 8px;
        }
        .dark .v-multiselect-input { border-color: #1f2937; background: #0b0f19; }
        .v-multiselect-input:hover { border-color: #9ca3af; }
        .ms-chips { display: flex; flex-wrap: wrap; gap: 4px; max-height: 80px; overflow-y: auto; }
        .ms-chip { 
            display: inline-flex; align-items: center; gap: 6px; 
            background: #f3f4f6; border-radius: 12px; padding: 2px 10px; 
            font-size: 12px; color: #374151;
        }
        .dark .ms-chip { background: #262b36; color: #d1d5db; }
        .ms-chip-x { cursor: pointer; opacity: 0.7; transition: opacity 0.2s; }
        .ms-chip-x:hover { opacity: 1; }
        .ms-count { 
            display: inline-flex; align-items: center; justify-content: center; 
            width: 22px; height: 22px; border-radius: 6px; background: #f3f4f6;
            font-size: 11px; font-weight: 600;
        }
        .dark .ms-count { background: #262b36; color: #fff; }
        .v-multiselect-dropdown { 
            position: absolute; left: 0; right: 0; top: calc(100% + 4px);
            background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); 
            padding: 8px; z-index: 100;
        }
        .dark .v-multiselect-dropdown { background: #0b0f19; border-color: #1f2937; }
        .ms-search { 
            width: 100%; border: 1px solid #e5e7eb; border-radius: 6px; 
            padding: 8px 12px; font-size: 13px; margin-bottom: 8px;
            outline: none;
        }
        .dark .ms-search { background: #0b0f19; border-color: #1f2937; color: #cbd5e1; }
        .ms-search:focus { border-color: var(--brand-color); }
        .ms-list { max-height: 200px; overflow-y: auto; }
        .ms-item { 
            display: flex; align-items: center; gap: 10px; padding: 8px; 
            border-radius: 6px; cursor: pointer; transition: background 0.2s;
        }
        .ms-item:hover { background: #f3f4f6; }
        .dark .ms-item:hover { background: #262b36; }
        .ms-item input[type="checkbox"] { 
            width: 16px; height: 16px; border-radius: 4px; accent-color: var(--brand-color);
        }
        .ms-empty { padding: 10px; color: #6b7280; font-size: 13px; text-align: center; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #374151; }

        {!! core()->getConfigData('general.content.custom_scripts.custom_css') !!}
    </style>

    {!! view_render_event('admin.layout.head.after') !!}
</head>

<body class="h-full font-inter dark:bg-gray-950">
    {!! view_render_event('admin.layout.body.before') !!}

    <div
        id="app"
        class="h-full"
    >
        {{-- #region agent log --}}
        @php
            try {
                file_put_contents(
                    base_path('.cursor/debug.log'),
                    json_encode([
                        'sessionId'    => 'debug-session',
                        'runId'        => 'pre-fix',
                        'hypothesisId' => 'H2',
                        'location'     => 'packages/Webkul/Admin/src/Resources/views/components/layouts/index.blade.php:app',
                        'message'      => 'Admin layout rendered',
                        'data'         => [
                            'url'        => request()->fullUrl(),
                            'routeName'  => optional(request()->route())->getName(),
                        ],
                        'timestamp'    => round(microtime(true) * 1000),
                    ]) . PHP_EOL,
                    FILE_APPEND
                );
            } catch (\Throwable $e) {
                // ignore logging errors
            }
        @endphp
        {{-- #endregion agent log --}}
        <!-- Flash Message Blade Component -->
        <x-admin::flash-group />

        <!-- Confirm Modal Blade Component -->
        <x-admin::modal.confirm />

        {!! view_render_event('admin.layout.content.before') !!}

        <!-- Page Header Blade Component -->
        <x-admin::layouts.header />

        <div
            class="group/container sidebar-collapsed flex gap-4"
            ref="appLayout"
        >
            <!-- Page Sidebar Blade Component -->
            <x-admin::layouts.sidebar.desktop />

            {{-- min-w-0 + overflow-x-clip: nada puede ser más ancho que la pantalla del
                 celular; si no, el teléfono aleja el zoom y la barra inferior queda afuera. --}}
            <div class="flex min-h-[calc(100vh-62px)] min-w-0 max-w-full flex-1 flex-col bg-gray-100 pt-3 transition-all duration-300 dark:bg-gray-950 max-lg:overflow-x-clip">
                <!-- Page Content Blade Component -->
                {{-- max-lg:pb-24: lo que tapa la barra inferior del celular. --}}
                <div class="px-4 pb-6 max-lg:px-3 max-lg:pb-24 ltr:lg:pl-[85px] rtl:lg:pr-[85px]">
                    {{ $slot }}
                </div>

                <!-- Powered By -->
                <div class="mt-auto pt-6 max-lg:hidden">
                    <div class="border-t bg-white py-5 text-center text-sm font-normal dark:border-gray-800 dark:bg-gray-900 dark:text-white max-md:py-3">
                        <p>{!! core()->getConfigData('general.settings.footer.label') !!}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Barra inferior del celular -->
        <x-admin::layouts.bottom-nav />

        {!! view_render_event('admin.layout.content.after') !!}
    </div>

    {!! view_render_event('admin.layout.body.after') !!}

    <script type="text/x-template" id="v-multiselect-template">
        <div class="v-multiselect-container w-full relative" ref="root">
            <div 
                class="v-multiselect-input flex items-center justify-between border rounded-md p-2 cursor-pointer min-h-[40px] dark:border-gray-800 dark:bg-gray-900"
                @click="toggleDropdown"
            >
                <div class="flex flex-wrap gap-1 items-center overflow-hidden">
                    <span v-if="!model.length" class="text-gray-400 text-sm">@{{ placeholder }}</span>
                    <span 
                        v-for="id in model" 
                        :key="id"
                        class="bg-gray-100 dark:bg-gray-800 text-xs px-2 py-1 rounded-full flex items-center gap-1 dark:text-gray-300"
                    >
                        @{{ getItemName(id) }}
                        <i class="icon-cross-large text-[10px] cursor-pointer" @click.stop="removeItem(id)"></i>
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <span v-if="model.length" class="text-xs bg-brandColor text-white rounded-full w-5 h-5 flex items-center justify-center">@{{ model.length }}</span>
                    <i :class="isOpen ? 'icon-up-arrow' : 'icon-down-arrow'" class="text-xl text-gray-600"></i>
                </div>
            </div>

            <div 
                v-if="isOpen" 
                class="v-multiselect-dropdown absolute top-full left-0 right-0 z-[100] mt-1 bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-md shadow-lg p-2"
            >
                <input 
                    type="text" 
                    v-model="searchTerm" 
                    class="w-full border dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300 rounded p-2 mb-2 text-sm focus:outline-none focus:border-brandColor"
                    placeholder="Buscar..."
                    @click.stop
                    ref="searchInput"
                />
                <ul class="max-h-60 overflow-y-auto custom-scrollbar">
                    <li 
                        v-for="item in filteredItems" 
                        :key="item.id"
                        class="flex items-center gap-2 p-2 hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer rounded transition-all"
                        @click.stop="toggleItem(item.id)"
                    >
                        <input 
                            type="checkbox" 
                            :checked="isSelected(item.id)"
                            class="w-4 h-4 accent-brandColor"
                            @click.stop="toggleItem(item.id)"
                        />
                        <span class="text-sm dark:text-gray-300">@{{ item.name || item.label }}</span>
                    </li>
                    <li v-if="!filteredItems.length" class="p-2 text-sm text-gray-500 text-center">
                        Sin resultados
                    </li>
                </ul>
            </div>
            
            <select :name="name" multiple class="hidden">
                <option v-for="id in model" :key="'val-'+id" :value="id" selected>@{{ id }}</option>
            </select>
        </div>
    </script>

    <script type="text/x-template" id="v-combo-manager-template">
        <div class="grid gap-2.5">
            <div class="table-responsive">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-800">
                            <th class="py-2.5 font-semibold text-gray-800 dark:text-white">@lang('admin::app.leads.view.products.product-name')</th>
                            <th class="py-2.5 font-semibold text-gray-800 dark:text-white w-[120px]">@lang('admin::app.leads.view.products.quantity')</th>
                            <th class="py-2.5 w-[50px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in items" :key="index" class="border-b border-gray-200 dark:border-gray-800 last:border-0">
                            <td class="py-2.5 pr-4">
                                 <x-admin::lookup
                                     ::src="'{{ route('admin.products.search') }}'"
                                     ::name="`combo_items[${index}][id]`"
                                     :placeholder="trans('admin::app.leads.view.products.product-name')"
                                     ::value="{ id: item.id, name: item.name }"
                                     @on-selected="(product) => onProductSelected(index, product)"
                                 />
                             </td>
                            <td class="py-2.5">
                                <input 
                                    type="number" 
                                    v-model="item.qty" 
                                    min="1"
                                    class="w-full border dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 rounded p-2 text-sm focus:outline-none focus:border-brandColor"
                                    @input="onUpdate"
                                />
                            </td>
                            <td class="py-2.5 pl-4 text-right">
                                <i 
                                    class="icon-delete text-2xl cursor-pointer text-gray-600 hover:text-red-600 transition-all"
                                    @click="removeItem(index)"
                                ></i>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <button
                type="button"
                class="flex max-w-max items-center gap-2 text-brandColor mt-2"
                @click="addItem"
            >
                <i class="icon-add text-md !text-brandColor"></i>
                @lang('admin::app.leads.view.products.add-more')
            </button>

            <input type="hidden" :name="name" :value="JSON.stringify(filteredItems)" />
        </div>
    </script>

    @stack('scripts')

    {!! view_render_event('admin.layout.vue-app-mount.before') !!}

    <script>
        /**
         * Load event, the purpose of using the event is to mount the application
         * after all of our `Vue` components which is present in blade file have
         * been registered in the app. No matter what `app.mount()` should be
         * called in the last.
         */
        window.addEventListener("load", function(event) {
            app.component('v-multiselect', {
                template: '#v-multiselect-template',
                props: {
                    items: { type: Array, default: () => [] },
                    modelValue: { type: [Array, String], default: () => [] },
                    placeholder: { type: String, default: 'Seleccionar...' },
                    name: { type: String, default: '' }
                },
                emits: ['update:modelValue'],
                data() {
                    return {
                        isOpen: false,
                        searchTerm: ''
                    };
                },
                computed: {
                    model() {
                        if (typeof this.modelValue === 'string') {
                            return this.modelValue ? this.modelValue.split(',') : [];
                        }
                        return Array.isArray(this.modelValue) ? this.modelValue.map(String) : [];
                    },
                    filteredItems() {
                        const q = this.searchTerm.trim().toLowerCase();
                        return q ? 
                            this.items.filter(i => (i.name || i.label || '').toLowerCase().includes(q)) : 
                            this.items;
                    }
                },
                methods: {
                    toggleDropdown() {
                        this.isOpen = !this.isOpen;
                        if (this.isOpen) {
                            this.$nextTick(() => this.$refs.searchInput?.focus());
                        }
                    },
                    getItemName(id) {
                        const item = this.items.find(i => String(i.id) === String(id));
                        return item ? (item.name || item.label) : id;
                    },
                    isSelected(id) {
                        return this.model.includes(String(id));
                    },
                    toggleItem(id) {
                        const idStr = String(id);
                        let next = [...this.model];
                        const index = next.indexOf(idStr);
                        if (index > -1) next.splice(index, 1);
                        else next.push(idStr);
                        this.$emit('update:modelValue', next);
                    },
                    removeItem(id) {
                        const next = this.model.filter(i => i !== String(id));
                        this.$emit('update:modelValue', next);
                    },
                    closeDropdown(e) {
                        if (this.$refs.root && !this.$refs.root.contains(e.target)) {
                            this.isOpen = false;
                        }
                    }
                },
                mounted() {
                    document.addEventListener('click', this.closeDropdown);
                },
                beforeUnmount() {
                    document.removeEventListener('click', this.closeDropdown);
                }
            });

            app.component('v-combo-manager', {
                template: '#v-combo-manager-template',
                props: ['modelValue', 'name'],
                data() {
                    let initialItems = [];
                    try {
                        initialItems = JSON.parse(this.modelValue || '[]');
                    } catch (e) {
                        initialItems = [];
                    }
                    if (initialItems.length === 0) {
                        initialItems.push({ id: '', name: '', qty: 1 });
                    }
                    return {
                        items: initialItems
                    };
                },
                computed: {
                    filteredItems() {
                        return this.items.filter(item => item.id && item.qty > 0);
                    }
                },
                watch: {
                    modelValue(newVal) {
                        try {
                            const parsed = JSON.parse(newVal || '[]');
                            if (JSON.stringify(parsed) !== JSON.stringify(this.filteredItems)) {
                                this.items = parsed.length > 0 ? parsed : [{ id: '', name: '', qty: 1 }];
                            }
                        } catch (e) {}
                    }
                },
                methods: {
                    addItem() {
                        this.items.push({ id: '', name: '', qty: 1 });
                    },
                    removeItem(index) {
                        this.items.splice(index, 1);
                        if (this.items.length === 0) {
                            this.addItem();
                        }
                        this.onUpdate();
                    },
                    onProductSelected(index, product) {
                        this.items[index].id = product.id;
                        this.items[index].name = product.name;
                        this.onUpdate();
                    },
                    onUpdate() {
                        this.$emit('update:modelValue', JSON.stringify(this.filteredItems));
                    }
                }
            });

            app.mount("#app");
        });
    </script>

    {!! view_render_event('admin.layout.vue-app-mount.after') !!}
</body>

</html>
