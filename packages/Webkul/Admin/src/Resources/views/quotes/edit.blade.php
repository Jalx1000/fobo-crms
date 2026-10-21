<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.quotes.edit.title')
    </x-slot>

    {!! view_render_event('admin.contacts.quotes.edit.form_controls.before', ['quote' => $quote]) !!}

    <x-admin::form
        :action="route('admin.quotes.update', $quote->id) . '?' . http_build_query(array_merge(
            request()->route()->parameters(),
            request()->all()
        ))"
        method="PUT"
    >
        <div class="flex flex-col gap-4 max-lg:gap-0">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 max-lg:sticky max-lg:top-[62px] max-lg:z-[10] max-lg:shadow-sm">
                <div class="flex flex-col gap-2">
                    <x-admin::breadcrumbs
                        name="quotes.edit"
                        :entity="$quote"
                    />

                    <div class="flex items-center gap-1">
                        <x-admin::layouts.volver listado="admin.quotes.index" />

                        <div class="text-xl font-bold dark:text-white">
                            @lang('admin::app.quotes.edit.title')
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                    <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                        {!! view_render_event('admin.contacts.quotes.edit.save_button.before', ['quote' => $quote]) !!}

                        <!-- Save button for person -->
                        {{--
                            data-una-vez: el form es un POST tradicional y el
                            boton no se deshabilita solo. Un doble clic creaba
                            dos cotizaciones iguales. El script de abajo lo
                            bloquea tras el primer envio valido.
                        --}}
                        <button
                            type="submit"
                            class="primary-button transition-opacity disabled:cursor-not-allowed disabled:opacity-60"
                            data-una-vez
                        >
                            @lang('admin::app.quotes.edit.save-btn')
                        </button>

                        {!! view_render_event('admin.contacts.quotes.edit.save_button.after', ['quote' => $quote]) !!}
                    </div>
                </div>
            </div>

            <v-quote :errors="errors">
                <x-admin::shimmer.quotes />
            </v-quote>
        </div>
    </x-admin::form>

    {!! view_render_event('admin.contacts.quotes.edit.form_controls.after', ['quote' => $quote]) !!}

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-quote-template"
        >
            <div class="box-shadow flex flex-col gap-4 rounded-lg border border-gray-200 bg-white max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                <div class="flex w-full gap-2 border-b border-gray-200 max-lg:gap-0 max-lg:overflow-x-auto dark:border-gray-800">
                    {!! view_render_event('admin.contacts.quotes.edit.tags.before', ['quote' => $quote]) !!}

                    <template
                        v-for="tab in tabs"
                        :key="tab.id"
                    >
                        <a
                            :href="'#' + tab.id"
                            :class="[
                                'inline-block px-3 py-2.5 border-b-2  text-sm font-medium max-lg:shrink-0 max-lg:whitespace-nowrap max-lg:py-3',
                                activeTab === tab.id
                                ? 'text-brandColor border-brandColor dark:brandColor dark:brandColor'
                                : 'text-gray-600 dark:text-gray-300  border-transparent hover:text-gray-800 hover:border-gray-400 dark:hover:border-gray-400  dark:hover:text-white'
                            ]"
                            @click="scrollToSection(tab.id)"
                            :text="tab.label"
                        ></a>
                    </template>

                    {!! view_render_event('admin.contacts.quotes.edit.tags.after', ['quote' => $quote]) !!}
                </div>

                <div class="flex flex-col gap-4 px-4 py-2">
                    {!! view_render_event('admin.contacts.quotes.edit.quote_information.before', ['quote' => $quote]) !!}

                    <!-- Quote information -->
                    <div
                        id="quote-info"
                        class="flex flex-col gap-4"
                    >
                        <div class="flex flex-col gap-1">
                            <p class="text-base font-semibold text-gray-800 dark:text-white">
                                @lang('admin::app.quotes.create.quote-info')
                            </p>

                            <p class="text-sm text-gray-600 dark:text-white">@lang('admin::app.quotes.create.quote-info-info')</p>
                        </div>

                        <div class="w-1/2 max-md:w-full">
                            <x-admin::attributes
                                :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                    'entity_type' => 'quotes',
                                    ['code', 'IN', ['subject']],
                                ])"
                                :custom-validations="[
                                    'expired_at' => [
                                        'required',
                                        'date_format:yyyy-MM-dd',
                                        'after:' .  \Carbon\Carbon::yesterday()->format('Y-m-d')
                                    ],
                                ]"
                                :entity="$quote"
                            />

                            <x-admin::attributes
                                :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                        'entity_type' => 'quotes',
                                        ['code', 'IN', ['description']],
                                    ])"
                                :custom-validations="[
                                    'expired_at' => [
                                        'required',
                                        'date_format:yyyy-MM-dd',
                                        'after:' .  \Carbon\Carbon::yesterday()->format('Y-m-d')
                                    ],
                                ]"
                                :entity="$quote"
                            />

                            <div class="flex gap-4">
                                <x-admin::attributes
                                    :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                        'entity_type' => 'quotes',
                                        ['code', 'IN', ['expired_at', 'user_id']],
                                    ])->sortBy('sort_order')"
                                    :custom-validations="[
                                        'expired_at' => [
                                            'required',
                                            'date_format:yyyy-MM-dd',
                                            'after:' .  \Carbon\Carbon::yesterday()->format('Y-m-d')
                                        ],
                                    ]"
                                    :entity="$quote"
                                />
                            </div>

                            <div class="flex gap-4">
                                <x-admin::attributes
                                    :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                        'entity_type' => 'quotes',
                                        ['code', 'IN', ['person_id']],
                                    ])->sortBy('sort_order')"
                                    :custom-validations="[
                                        'expired_at' => [
                                            'required',
                                            'date_format:yyyy-MM-dd',
                                            'after:' .  \Carbon\Carbon::yesterday()->format('Y-m-d')
                                        ],
                                    ]"
                                    :entity="$quote"
                                />

                                <x-admin::attributes.edit.lookup />

                                @php
                                    $leadId = old('lead_id') ?? optional($quote->leads->first())->id;

                                    $lookUpEntityData = app('Webkul\Attribute\Repositories\AttributeRepository')->getLookUpEntity('leads', $leadId);
                                @endphp

                                <x-admin::form.control-group class="w-full">
                                    <x-admin::form.control-group.label>
                                        @lang('admin::app.quotes.create.link-to-lead')
                                    </x-admin::form.control-group.label>

                                    <v-lookup-component
                                        :key="leadEntity.id"
                                        :attribute="{'code': 'lead_id', 'name': 'Lead', 'lookup_type': 'leads'}"
                                        :value="leadEntity"
                                        can-add-new="true"
                                        @lookup-removed="setLeadEntity"
                                    ></v-lookup-component>
                                </x-admin::form.control-group>
                            </div>

                            <!-- Custom Attributes -->
                            <x-admin::attributes
                                :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                                    'entity_type'     => 'quotes',
                                    'is_user_defined' => 1,
                                    // La sucursal se dibuja aparte, como select
                                    // simple: ver `_sucursal-select`.
                                    ['code', '<>', 'sucursal'],
                                ])->sortBy('sort_order')"
                                :custom-validations="[
                                    'expired_at' => [
                                        'required',
                                        'date_format:yyyy-MM-dd',
                                        'after:' .  \Carbon\Carbon::yesterday()->format('Y-m-d')
                                    ],
                                ]"
                                :entity="$quote"
                            />

                            @include('admin::quotes._sucursal-select')
                        </div>
                    </div>

                    {!! view_render_event('admin.contacts.quotes.edit.quote_information.after', ['quote' => $quote]) !!}

                    {{--
                        La seccion de direccion se quito el 11/09: el formato
                        RG-VCO-005 no la lleva. La cabecera necesita razon
                        social, NIT, sucursal y orden de compra, que estan en
                        los atributos de la cotizacion.

                        Los atributos siguen existiendo y el PDF los imprime si
                        la cotizacion ya los tenia: no se pierde nada de lo ya
                        emitido.
                    --}}

                    {!! view_render_event('admin.contacts.quotes.edit.quote_information.before', ['quote' => $quote]) !!}

                    <!-- Quote Item Information -->
                    <div
                        id="quote-items"
                        class="flex flex-col gap-4"
                    >
                        <div class="flex flex-col gap-1">
                            <p class="text-base font-semibold text-gray-800 dark:text-white">
                                @lang('admin::app.quotes.create.quote-items')
                            </p>

                            <p class="text-sm text-gray-600 dark:text-white">
                                @lang('admin::app.quotes.create.quote-item-info')
                            </p>
                        </div>

                        <!-- Quote Item List Vue Component -->
                        <v-quote-item-list :errors="errors"></v-quote-item-list>
                    </div>

                    {!! view_render_event('admin.contacts.quotes.edit.quote_information.after', ['quote' => $quote]) !!}
                </div>

                {!! view_render_event('admin.contacts.quotes.edit.form_controls.after', ['quote' => $quote]) !!}
            </div>
        </script>

        <script
            type="text/x-template"
            id="v-quote-item-list-template"
        >
            <div class="flex flex-col gap-4">
                {{-- Moneda una sola vez: con «(BOB)» en cada encabezado no entraban y se pisaban. --}}
                <p class="-mb-2 text-right text-xs text-gray-500 dark:text-gray-400">
                    @lang('admin::app.quotes.create.montos-en', ['symbol' => core()->currencySymbol(config('app.currency'))])
                </p>

                <div class="block w-full overflow-x-auto max-lg:overflow-visible">
                    <!--
                        min-w mayor que el del componente: con las columnas del
                        formato RG-VCO-005 son doce, y en 800px quedan tan
                        angostas que los codigos no se leen. La tabla scrollea
                        en horizontal dentro de su contenedor.
                    -->
                    {{-- Celular: cada producto es una tarjeta (ver v-quote-item); sin encabezados. --}}
                    <x-admin::table class="!min-w-[1100px] max-lg:!min-w-0 max-lg:block max-lg:border-0 max-lg:[&>thead]:hidden max-lg:[&>tbody]:flex max-lg:[&>tbody]:flex-col max-lg:[&>tbody]:gap-3">
                        <!-- Table Head -->
                        <x-admin::table.thead>
                            <x-admin::table.thead.tr>
                                {{-- w-[220px]: la columna del producto se aplastaba contra «Medida» (la tabla es table-fixed). --}}
                                <x-admin::table.th class="!w-[260px] !px-2 !py-2.5 text-xs uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.product-name')
                                </x-admin::table.th>

                                <x-admin::table.th class="!px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.medida')
                                </x-admin::table.th>

                                <x-admin::table.th class="!px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.cod-tr4')
                                </x-admin::table.th>

                                <x-admin::table.th class="!px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.cod-barra')
                                </x-admin::table.th>

                                <x-admin::table.th class="!w-[72px] !px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.unidad')
                                </x-admin::table.th>

                                <x-admin::table.th class="!w-[96px] !px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.quantity')
                                </x-admin::table.th>

                                <x-admin::table.th class="!px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.price')
                                </x-admin::table.th>

                                <x-admin::table.th class="!px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.amount')
                                </x-admin::table.th>

                                <x-admin::table.th class="!px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.discount')
                                </x-admin::table.th>

                                <x-admin::table.th class="!px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.tax')
                                </x-admin::table.th>

                                <x-admin::table.th class="!px-2 !py-2.5 text-center !whitespace-normal text-xs uppercase leading-tight tracking-wide text-gray-600 dark:text-gray-300">
                                    @lang('admin::app.quotes.create.total')
                                </x-admin::table.th>

                                <x-admin::table.th
                                    v-if="products.length > 1"
                                    class="!w-[52px] !px-2 !py-2.5"
                                >
                                    <span class="sr-only">@lang('admin::app.quotes.create.action')</span>
                                </x-admin::table.th>
                            </x-admin::table.thead.tr>
                        </x-admin::table.thead>

                        <!-- Table Body -->
                        <x-admin::table.tbody>
                            <!-- Quote Item Vue component -->
                            <template
                                v-for='(product, index) in products'
                                :key="index"
                            >
                                <v-quote-item
                                    :product="product"
                                    :index="index"
                                    :errors="errors"
                                    @onRemoveProduct="removeProduct($event)"
                                ></v-quote-item>
                            </template>
                        </x-admin::table.tbody>
                    </x-admin::table>
                </div>

                <!-- Add New Quote Item -->
                {{--
                    <button> y no <span>: un span clickeable no recibe foco de
                    teclado, asi que no se podia agregar una linea sin mouse.
                --}}
                <button
                    type="button"
                    class="text-md flex max-w-max cursor-pointer items-center gap-2 rounded text-brandColor transition-colors hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brandColor"
                    @click="addProduct"
                >
                    @lang('admin::app.quotes.create.add-item')
                </button>

                <div class="flex justify-end">
                    <div class="grid w-[348px] gap-4 rounded-lg bg-gray-100 p-4 text-sm dark:bg-gray-950 dark:text-white">
                        <div class="flex w-full justify-between gap-x-5">
                            @lang('admin::app.quotes.create.sub-total', ['symbol' => core()->currencySymbol(config('app.currency'))])

                            <input
                                type="hidden"
                                name="sub_total"
                                class="control"
                                :value="subTotal"
                                readonly
                            >

                            <p>@{{ $admin.formatPrice(subTotal) }}</p>
                        </div>

                        <div class="flex w-full justify-between gap-x-5">
                            @lang('admin::app.quotes.create.total-discount', ['symbol' => core()->currencySymbol(config('app.currency'))])

                            <input
                                type="hidden"
                                name="discount_amount"
                                :value="discountAmount"
                            >

                            <p>@{{ $admin.formatPrice(discountAmount) }}</p>
                        </div>

                        <div class="flex w-full justify-between gap-x-5">
                            @lang('admin::app.quotes.create.total-tax', ['symbol' => core()->currencySymbol(config('app.currency'))])

                            <input
                                type="hidden"
                                name="tax_amount"
                                :value="taxAmount"
                            >

                            <p>@{{ $admin.formatPrice(taxAmount) }}</p>
                        </div>

                        <div class="flex w-full justify-between gap-x-5">
                            <span class="shrink-0 whitespace-nowrap">@lang('admin::app.quotes.create.total-adjustment', ['symbol' => core()->currencySymbol(config('app.currency'))])</span>

                            <x-admin::form.control-group.control
                                type="inline"
                                ::name="`adjustment_amount`"
                                ::value="adjustmentAmount"
                                rules="required|decimal:4"
                                ::errors="errors"
                                :label="trans('admin::app.quotes.create.adjustment-amount')"
                                :placeholder="trans('admin::app.quotes.create.adjustment-amount')"
                                @on-change="(event) => adjustmentAmount = event.value"
                            />
                        </div>

                        <div class="flex w-full justify-between gap-x-5">
                            @lang('admin::app.quotes.create.grand-total', ['symbol' => core()->currencySymbol(config('app.currency'))])

                            <input
                                type="hidden"
                                name="grand_total"
                                :value="grandTotal"
                            >

                            <p>@{{ $admin.formatPrice(grandTotal) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </script>

        <script
            type="text/x-template"
            id="v-quote-item-template"
        >
            {{--
                Celular: la fila es una tarjeta de dos columnas; cada celda
                muestra su nombre arriba (data-etiqueta), porque los
                encabezados de la tabla no se ven.
            --}}
            <x-admin::table.thead.tr class="align-top max-lg:grid max-lg:grid-cols-2 max-lg:gap-x-3 max-lg:gap-y-3 max-lg:rounded-lg max-lg:border max-lg:border-gray-200 max-lg:p-3 dark:max-lg:border-gray-800">
                <!-- Quote Product Name -->
                <x-admin::table.td class="!px-2 !py-2 max-lg:col-span-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal">
                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::lookup
                            ::src="src"
                            ::name="`${inputName}[product_id]`"
                            ::params="params"
                            ::value="{ id: product.product_id, name: product.name }"
                            @on-selected="(product) => addProduct(product)"
                            :placeholder="trans('admin::app.quotes.edit.search-products')"
                        />
                    </x-admin::form.control-group>

                    {{--
                        Variante y descripcion debajo del buscador, con el mismo
                        alto (h-8) y espaciado: antes eran tres controles de
                        alturas y tipografias distintas apilados en la celda.
                    --}}
                    <div v-if="product.product_id" class="mt-2 flex flex-col gap-1.5">

                        <!--
                            El select de variante queda junto al producto: es lo que
                            completa las columnas de la derecha. Los codigos en si
                            van como COLUMNAS, igual que en el PDF.
                        -->
                        <select
                            v-if="product.product_id && variantes.length"
                            :name="`${inputName}[variante_id]`"
                            :aria-label="`@lang('admin::app.quotes.create.variante-placeholder') ${product.name || ''}`"
                            class="h-9 w-full cursor-pointer rounded border border-gray-200 px-2 text-xs text-gray-800 transition-colors hover:border-gray-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brandColor dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                            @change="elegirVariante($event.target.value)"
                        >
                            <option value="">@lang('admin::app.quotes.create.variante-placeholder')</option>
                            <option
                                v-for="v in variantes"
                                :key="v.id"
                                :value="v.id"
                                :selected="String(v.id) === String(product.variante_id)"
                            >@{{ v.etiqueta }}</option>
                        </select>


                        <!--
                            Descripcion de la linea: es lo que sale en la columna
                            ITEM del PDF. Se completa sola con el nombre del ERP de
                            la variante y queda editable, porque a veces el cliente
                            pide que diga otra cosa en su cotizacion.
                        -->
                        <input
                            v-if="product.product_id"
                            :name="`${inputName}[name]`"
                            :aria-label="`@lang('admin::app.quotes.create.descripcion-item') ${product.name || ''}`"
                            v-model="product.name"
                            placeholder="@lang('admin::app.quotes.create.descripcion-item')"
                            @input="product.descripcionEditada = true"
                            class="h-9 w-full rounded border border-gray-200 px-2 text-xs text-gray-800 placeholder:text-gray-500 transition-colors hover:border-gray-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brandColor dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:placeholder:text-gray-400"
                        />
                    </div>
                </x-admin::table.td>

                <!-- Medida · COD TR4 · Codigo de barra · Unidad -->
                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.medida') }}">
                    <input
                        :name="`${inputName}[medida]`"
                        :aria-label="`@lang('admin::app.quotes.create.medida') ${product.name || ''}`"
                        v-model="product.medida"
                        placeholder="@lang('admin::app.quotes.create.medida')"
                        class="h-9 w-full rounded border border-gray-200 px-2 text-xs text-gray-800 placeholder:text-gray-500 transition-colors hover:border-gray-400 focus:border-gray-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brandColor dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:placeholder:text-gray-400"
                    />
                </x-admin::table.td>

                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.cod-tr4') }}">
                    <input
                        :name="`${inputName}[codigo_tr4]`"
                        :aria-label="`@lang('admin::app.quotes.create.cod-tr4') ${product.name || ''}`"
                        v-model="product.codigo_tr4"
                        placeholder="@lang('admin::app.quotes.create.cod-tr4')"
                        class="h-9 w-full rounded border border-gray-200 px-2 text-xs text-gray-800 placeholder:text-gray-500 transition-colors hover:border-gray-400 focus:border-gray-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brandColor dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:placeholder:text-gray-400"
                    />
                </x-admin::table.td>

                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.cod-barra') }}">
                    <input
                        :name="`${inputName}[codigo_barra]`"
                        :aria-label="`@lang('admin::app.quotes.create.cod-barra') ${product.name || ''}`"
                        inputmode="numeric"
                        v-model="product.codigo_barra"
                        placeholder="@lang('admin::app.quotes.create.cod-barra')"
                        class="h-9 w-full rounded border border-gray-200 px-2 text-xs text-gray-800 placeholder:text-gray-500 transition-colors hover:border-gray-400 focus:border-gray-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brandColor dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:placeholder:text-gray-400"
                    />
                </x-admin::table.td>

                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.unidad') }}">
                    <input
                        :name="`${inputName}[unidad]`"
                        :aria-label="`@lang('admin::app.quotes.create.unidad') ${product.name || ''}`"
                        v-model="product.unidad"
                        placeholder="@lang('admin::app.quotes.create.unidad')"
                        class="h-9 w-full rounded border border-gray-200 px-2 text-center text-xs text-gray-800 transition-colors hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                    />
                </x-admin::table.td>

                <!-- Quantity -->
                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.quantity') }}">
                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.control
                            type="text"
                            ::name="`${inputName}[quantity]`"
                            v-model="product.quantity"
                            rules="required|decimal:4"
                            :label="trans('admin::app.quotes.create.quantity')"
                            inputmode="decimal"
                            class="!h-9 !py-0 !px-2 text-right !text-xs"
                        />

                        <x-admin::form.control-group.error ::name="`${inputName}[quantity]`" />
                    </x-admin::form.control-group>
                </x-admin::table.td>

                <!-- Price -->
                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.price') }}">
                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.control
                            type="text"
                            ::name="`${inputName}[price]`"
                            v-model="product.price"
                            rules="required|decimal:4"
                            :label="trans('admin::app.quotes.create.price')"
                            inputmode="decimal"
                            class="!h-9 !py-0 !px-2 text-right !text-xs"
                        />

                        <x-admin::form.control-group.error ::name="`${inputName}[price]`" />
                    </x-admin::form.control-group>
                </x-admin::table.td>

                <!-- Total -->
                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.amount') }}">
                    <input type="hidden" :name="`${inputName}[total]`" :value="product.price * product.quantity">

                    <p class="flex h-9 items-center justify-end text-xs text-gray-600 dark:text-gray-300" v-text="$admin.formatPrice(product.price * product.quantity)"></p>
                </x-admin::table.td>

                <!-- Discount Amount -->
                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.discount') }}">
                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.control
                            type="text"
                            ::name="`${inputName}[discount_amount]`"
                            v-model="product.discount_amount"
                            rules="required|decimal:4"
                            :label="trans('admin::app.quotes.create.discount-amount')"
                            inputmode="decimal"
                            class="!h-9 !py-0 !px-2 text-right !text-xs"
                        />

                        <x-admin::form.control-group.error ::name="`${inputName}[discount_amount]`" />
                    </x-admin::form.control-group>
                </x-admin::table.td>

                <!-- Tax Amount -->
                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.tax') }}">
                    <x-admin::form.control-group class="!mb-0">
                        <x-admin::form.control-group.control
                            type="text"
                            ::name="`${inputName}[tax_amount]`"
                            v-model="product.tax_amount"
                            rules="required|decimal:4"
                            :label="trans('admin::app.quotes.create.tax-amount')"
                            inputmode="decimal"
                            class="!h-9 !py-0 !px-2 text-right !text-xs"
                        />

                        <x-admin::form.control-group.error ::name="`${inputName}[tax_amount]`" />
                    </x-admin::form.control-group>
                </x-admin::table.td>

                <!-- Total with Discount -->
                <x-admin::table.td class="!px-2 !py-2 max-lg:block max-lg:!p-0 max-lg:whitespace-normal max-lg:before:mb-1 max-lg:before:block max-lg:before:text-xs max-lg:before:font-medium max-lg:before:text-gray-500 max-lg:before:content-[attr(data-etiqueta)]" data-etiqueta="{{ trans('admin::app.quotes.create.total') }}">
                    <input type="hidden" :name="`${inputName}[final_total]`" :value="totalDeLinea">

                    <p class="flex h-9 items-center justify-end text-xs font-semibold" v-text="$admin.formatPrice(totalDeLinea)"></p>
                </x-admin::table.td>

                <!-- Action -->
                <x-admin::table.td
                    v-if="$parent.products.length > 1"
                    class="!px-2 !py-2 text-center max-lg:col-span-2 max-lg:-mt-1 max-lg:flex max-lg:justify-end max-lg:border-t max-lg:border-gray-100 max-lg:!px-0 max-lg:!pb-0 max-lg:pt-2 dark:max-lg:border-gray-800"
                >
                    <x-admin::form.control-group class="!mb-0">
                        {{--
                            <button> y no <i>: un icono clickeable no recibe
                            foco de teclado ni tiene nombre accesible, asi que
                            no hay forma de quitar una linea sin mouse.
                        --}}
                        <button
                            type="button"
                            @click="removeProduct"
                            class="flex cursor-pointer items-center gap-1 rounded text-gray-600 transition-colors hover:text-red-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brandColor dark:text-gray-300 max-lg:text-sm max-lg:text-red-600"
                            :aria-label="`@lang('admin::app.quotes.create.remove-item') ${product.name || ''}`"
                        >
                            <span class="icon-delete text-2xl"></span>

                            <span class="lg:hidden">@lang('admin::app.quotes.create.remove-item')</span>
                        </button>
                    </x-admin::form.control-group>
                </x-admin::table.td>
            </x-admin::table.thead.tr>
        </script>

        <script type="module">
            app.component('v-quote', {
                template: '#v-quote-template',

                props: ['errors'],

                data() {
                    return {
                        activeTab: 'quote-info',

                        tabs: [
                            { id: 'quote-info', label: '@lang('admin::app.quotes.create.quote-info')' },
                            { id: 'quote-items', label: '@lang('admin::app.quotes.create.quote-items')' }
                        ],

                        leadEntity: @json($lookUpEntityData ?? []),
                    };
                },

                methods: {
                    /**
                     * Scroll to the section.
                     *
                     * @param {String} tabId
                     *
                     * @returns {void}
                     */
                    scrollToSection(tabId) {
                        const section = document.getElementById(tabId);

                        if (section) {
                            section.scrollIntoView({ behavior: 'smooth' });
                        }
                    },

                    setLeadEntity($event) {
                        this.leadEntity = $event;
                    },
                },
            });

            app.component('v-quote-item-list', {
                template: '#v-quote-item-list-template',

                props: ['errors'],

                data() {
                    return {
                        adjustmentAmount: 0,

                        products: @json($items),
                    }
                },

                computed: {
                    /**
                     * Calculate the sub total of the products.
                     *
                     * @returns {Number}
                     */
                    subTotal() {
                        let total = 0;

                        this.products.forEach(product => {
                            total += parseFloat(product.price * product.quantity);
                        });

                        return total;
                    },

                    /**
                     * Calculate the total discount amount of the products.
                     *
                     * @returns {Number}
                     */
                    discountAmount() {
                        let total = 0;

                        this.products.forEach(product => total += parseFloat(product.discount_amount));

                        return total;
                    },

                    /**
                     * Calculate the total tax amount of the products.
                     *
                     * @returns {Number}
                     */
                    taxAmount() {
                        let total = 0;

                        this.products.forEach(product => total += parseFloat(product.tax_amount));

                        return total;
                    },

                    /**
                     * Calculate the grand total of the products.
                     *
                     * @returns {Number}
                     */
                    grandTotal() {
                        let total = 0;

                        this.products.forEach(product => {
                            total += parseFloat(product.price * product.quantity) + parseFloat(product.tax_amount) - parseFloat(product.discount_amount) + parseFloat(this.adjustmentAmount);
                        });

                        return total;
                    },
                },

                mounted() {
                    /*
                     * Marca los campos de entrega cuando el asesor los edita, para
                     * que la propuesta automatica deje de pisarlos. Delegado en
                     * document: los campos los renderiza el componente de
                     * atributos, fuera de esta plantilla.
                     */
                    document.addEventListener('change', e => {
                        if (['tiempo_entrega', 'lugar_entrega'].includes(e.target?.name) && e.isTrusted) {
                            e.target.dataset.editadoAMano = '1';
                        }
                    });
                },

                methods: {
                    /**
                     * Add a new product.
                     *
                     * @returns {void}
                     */
                    addProduct() {
                        this.products.push({
                            id: null,
                            product_id: null,
                            name: '',
                            quantity: 1,
                            total: 0,
                            price: 0,
                            discount_amount: 0,
                            tax_amount: 0,
                        });
                    },

                    /**
                     * Propone tiempo y lugar de entrega desde los items.
                     *
                     * Un pedido tiene un solo bloque de condiciones: se combina
                     * por lo mas restrictivo. Tapas impresas arrastran a 20 dias
                     * todo el pedido; si algo se recoge en planta, todo se
                     * recoge en planta.
                     *
                     * Es una PROPUESTA: escribe en los campos y el asesor los
                     * cambia. Una vez que los toca, no se vuelven a pisar.
                     */
                    proponerEntrega() {
                        const items = this.products
                            .filter(p => p.product_id && p.entrega)
                            .map(p => {
                                const cantidad = Number(p.quantity) || 0;
                                const desde = p.entrega.domicilio_desde;

                                let lugar = p.entrega.lugar || 'Puesto almacén cliente';

                                // El umbral solo mueve de almacen a domicilio:
                                // quien recoge en planta, recoge en planta.
                                if (lugar === 'Puesto almacén cliente' && desde && cantidad >= Number(desde)) {
                                    lugar = 'A domicilio';
                                }

                                return { tiempo: p.variante_tiempo || p.entrega.tiempo || 'Inmediata', lugar };
                            });

                        if (! items.length) return;

                        const dias = t => {
                            const s = String(t).toLowerCase();
                            if (! s || s.includes('inmediat')) return 0;
                            const m = s.match(/(\d+)/);
                            if (! m) return Number.MAX_SAFE_INTEGER;
                            const n = Number(m[1]);
                            if (s.includes('seman')) return n * 7;
                            if (s.includes('mes')) return n * 30;
                            return n;
                        };

                        const tiempo = items.reduce((peor, i) => dias(i.tiempo) > dias(peor) ? i.tiempo : peor, 'Inmediata');
                        const lugares = items.map(i => i.lugar);

                        const lugar = lugares.includes('Recoge en planta')
                            ? 'Recoge en planta'
                            : (lugares.every(l => l === 'A domicilio') ? 'A domicilio' : 'Puesto almacén cliente');

                        this.escribirCampo('tiempo_entrega', tiempo);
                        this.escribirCampo('lugar_entrega', lugar);
                    },

                    /**
                     * Escribe en un campo del formulario disparando `input`.
                     *
                     * VeeValidate escucha ese evento: asignar `.value` a secas
                     * no actualiza su estado y el valor no llega al submit.
                     *
                     * No pisa lo que el asesor ya edito: el listener de
                     * `quotes-entrega-manual` marca el campo la primera vez que
                     * lo tocan a mano.
                     */
                    escribirCampo(nombre, valor) {
                        const el = document.querySelector(`[name="${nombre}"]`);

                        if (! el || el.dataset.editadoAMano) return;

                        /*
                         * `lugar_entrega` es un atributo SELECT: sus <option>
                         * llevan el id de la opcion como value, no el texto.
                         * Asignar el texto no selecciona nada y se guarda 0.
                         * Por eso se busca la opcion por su etiqueta.
                         *
                         * `tiempo_entrega` es texto libre y entra por el else.
                         */
                        if (el.tagName === 'SELECT') {
                            const op = [...el.options].find(o => o.text.trim() === String(valor).trim());

                            if (! op) return;

                            el.value = op.value;
                        } else {
                            el.value = valor;
                        }

                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    },

                    /**
                     * Remove the product.
                     *
                     * @param {Object} product
                     */
                    removeProduct(product) {
                        this.$emitter.emit('open-confirm-modal', {
                            agree: () => {
                                if (this.products.length === 1) {
                                    this.products = [{
                                        id: null,
                                        product_id: null,
                                        name: '',
                                        quantity: null,
                                        total: 0,
                                        price: null,
                                        discount_amount: null,
                                        tax_amount: null,
                                    }];
                                } else {
                                    const index = this.products.indexOf(product);

                                    if (index !== -1) {
                                        this.products.splice(index, 1);
                                    }
                                }
                            },
                        });
                    },
                },
            });

            app.component('v-quote-item', {
                template: '#v-quote-item-template',

                props: ['index', 'product', 'errors'],

                data() {
                    return {
                        state: this.product['product_id'] ? 'old' : '',

                        products: [],

                        /*
                         * Declarado aca y no solo asignado en addProduct(): una
                         * fila que ya viene con producto evalua `variantes.length`
                         * en el template antes de que el asesor toque nada, y un
                         * undefined ahi aborta el render de TODA la lista.
                         */
                        variantes: this.product.variantes ?? [],
                    }
                },

                created() {
                    /*
                     * Los montos llegan de la base como «460.0000»: ahora son
                     * campos a la vista y se mostraban así. Se dejan en «460».
                     */
                    ['quantity', 'price', 'discount_amount', 'tax_amount'].forEach((campo) => {
                        const valor = parseFloat(this.product[campo]);

                        if (! isNaN(valor)) {
                            this.product[campo] = String(valor);
                        }
                    });
                },

                computed: {
                    /**
                     * Total de la línea: monto + impuesto - descuento (lo que va
                     * en `final_total`). Vacío cuenta como 0 mientras se escribe.
                     */
                    totalDeLinea() {
                        const numero = (valor) => parseFloat(valor) || 0;

                        return numero(this.product.price) * numero(this.product.quantity)
                            + numero(this.product.tax_amount)
                            - numero(this.product.discount_amount);
                    },

                    /**
                     * Get the input name.
                     *
                     * @returns {String}
                     */
                    inputName() {
                        if (this.product.id) {
                            return "items[" + this.product.id + "]";
                        }

                        return "items[item_" + this.index + "]";
                    },

                    /**
                     * Get the source URL.
                     *
                     * @returns {String}
                     */
                    src() {
                        return "{{ route('admin.products.search') }}";
                    },

                    params() {
                        return {
                            params: {
                                query: this.product.name,
                            },
                        };
                    },
                },

                methods: {
                    /**
                     * Add the product.
                     *
                     * @param {Object} result
                     *
                     * @return {void}
                     */
                    addProduct(result) {
                        this.product.product_id = result.id;
                        this.product.name = result.name;
                        this.product.price = result.price ?? 0;
                        this.product.quantity = 1;
                        this.product.discount_amount = 0;
                        this.product.tax_amount = 0;

                        /*
                         * Variantes y entrega del producto elegido. Vienen en la
                         * misma respuesta del buscador: pedirlas aparte por cada
                         * fila seria una espera visible al elegir.
                         */
                        this.variantes = result.variantes ?? [];
                        this.product.entrega = result.entrega ?? null;
                        this.product.variante_id = null;
                        this.product.descripcionEditada = false;
                        this.product.codigo_tr4 = '';
                        this.product.codigo_barra = '';
                        this.product.medida = '';
                        this.product.unidad = '';

                        if (this.variantes.length === 1) {
                            this.elegirVariante(this.variantes[0].id);
                        }

                        this.avisarEntrega();
                    },

                    /**
                     * Completa los codigos del item desde la variante elegida.
                     */
                    elegirVariante(id) {
                        const v = this.variantes.find(v => String(v.id) === String(id));

                        this.product.variante_id = v ? v.id : null;

                        if (! v) {
                            return this.avisarEntrega();
                        }

                        this.product.codigo_tr4 = v.codigo_tr4 ?? '';
                        this.product.codigo_barra = v.codigo_barra ?? '';
                        this.product.medida = v.medida ?? '';
                        this.product.unidad = v.unidad ?? 'PZA';

                        // La descripcion se rellena con el nombre del ERP, que
                        // es el que el cliente reconoce. No pisa lo que el
                        // asesor ya escribio a mano.
                        if (v.nombre_erp && ! this.product.descripcionEditada) {
                            this.product.name = v.nombre_erp;
                        }
                        this.product.variante_tiempo = v.tiempo_entrega ?? null;

                        this.avisarEntrega();
                    },

                    /**
                     * El bloque de condiciones se arma con TODAS las filas, asi
                     * que el calculo vive en el componente padre.
                     */
                    avisarEntrega() {
                        this.$emit('onEntregaChange');
                    },

                    /**
                     * Remove the product.
                     *
                     * @return {void}
                     */
                    removeProduct() {
                        this.$emit('onRemoveProduct', this.product);
                    },
                },
            });
        </script>
    @endPushOnce

    @pushOnce('styles')
        <style>
            html {
                scroll-behavior: smooth;
            }
        </style>
    @endPushOnce
@pushOnce('scripts')
    <script>
        /*
         * Razon social <- nombre del cliente.
         *
         * El componente lookup de `person_id` guarda el id en un input oculto y
         * muestra el nombre en un <span> al lado. No emite ningun evento que
         * esta vista escuche (`handleLookupAdded` no existe en cotizaciones),
         * asi que se observa el input: cuando su value cambia, el nombre visible
         * ya esta en el DOM.
         *
         * Solo completa si razon social esta VACIA. Lo que el asesor escriba
         * manda: se cotiza a una persona y se factura a la empresa de su grupo,
         * que es justo el caso que justifica que el campo exista.
         *
         * Es una comodidad del formulario; la garantia esta en el servidor
         * (QuoteController::razonSocialPorDefecto), que tambien cubre al agente.
         */
        (function () {
            const nombreDelCliente = () => {
                const oculto = document.querySelector('input[name="person_id"]');

                if (! oculto || ! oculto.value) return null;

                // El nombre visible vive en el mismo bloque que el input.
                const bloque = oculto.closest('.relative')?.parentElement ?? oculto.parentElement;
                const texto = bloque?.querySelector('span[title], span.overflow-hidden')?.textContent?.trim();

                return texto || null;
            };

            const completar = () => {
                const razon = document.querySelector('input[name="razon_social"]');

                if (! razon || razon.value.trim() !== '' || razon.dataset.editadoAMano) return;

                const nombre = nombreDelCliente();

                if (! nombre) return;

                razon.value = nombre;
                razon.dispatchEvent(new Event('input', { bubbles: true }));
                razon.dispatchEvent(new Event('change', { bubbles: true }));
            };

            // Una vez que el asesor la toca, no se vuelve a tocar.
            document.addEventListener('input', e => {
                if (e.target?.name === 'razon_social' && e.isTrusted) {
                    e.target.dataset.editadoAMano = '1';
                }
            });

            // El lookup escribe el id sin disparar eventos del DOM.
            const observar = () => {
                const oculto = document.querySelector('input[name="person_id"]');

                if (! oculto) return setTimeout(observar, 300);

                new MutationObserver(() => setTimeout(completar, 50))
                    .observe(oculto, { attributes: true, attributeFilter: ['value'] });

                document.addEventListener('click', () => setTimeout(completar, 150));

                completar();
            };

            observar();
        })();
    </script>
@endPushOnce
@pushOnce('scripts')
        <script>
            /*
             * Un solo envio por formulario. Delegado en document: el boton lo
             * renderiza Vue despues de este bloque.
             */
            document.addEventListener('submit', e => {
                const boton = e.target?.querySelector?.('[data-una-vez]');

                if (! boton) return;

                /*
                 * Diferido y en fase de burbuja, NO de captura. La version
                 * anterior deshabilitaba el boton antes de que VeeValidate
                 * procesara el submit, y un boton disabled en ese instante
                 * hacia que el formulario no se enviara: el asesor guardaba y
                 * no pasaba nada. Ahora se bloquea recien despues de que el
                 * envio ya salio, y solo si nadie lo cancelo.
                 */
                setTimeout(() => {
                    if (e.defaultPrevented) return;

                    boton.disabled = true;
                    boton.setAttribute('aria-busy', 'true');
                }, 0);
            });

            window.addEventListener('pageshow', () => {
                document.querySelectorAll('[data-una-vez]').forEach(b => {
                    b.disabled = false;
                    b.removeAttribute('aria-busy');
                });
            });
        </script>
    @endPushOnce
</x-admin::layouts>
