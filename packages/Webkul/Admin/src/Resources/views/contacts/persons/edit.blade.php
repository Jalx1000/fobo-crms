
<x-admin::layouts>
    <!-- Page Title -->
    <x-slot:title>
        @lang('admin::app.contacts.persons.edit.title')
    </x-slot>

    {!! view_render_event('admin.persons.edit.form.before') !!}

    <x-admin::form
        :action="route('admin.contacts.persons.update', $person->id)"
        method="PUT"
        enctype="multipart/form-data"
    >
        {{-- Celular: de borde a borde, sin el gris entre bloques (como la cita). --}}
        <div class="flex flex-col gap-4 max-lg:gap-0">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    {!! view_render_event('admin.persons.edit.breadcrumbs.before') !!}

                    <x-admin::breadcrumbs
                        name="contacts.persons.edit"
                        :entity="$person"
                    />

                    {!! view_render_event('admin.persons.edit.breadcrumbs.after') !!}

                    <div class="flex items-center gap-1">
                        <x-admin::layouts.volver listado="admin.contacts.persons.index" />

                        <div class="text-xl font-bold dark:text-white">
                            @lang('admin::app.contacts.persons.edit.title')
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                    <!--  Save button for Person -->
                    <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                        {!! view_render_event('admin.persons.edit.save_button.before') !!}

                        <button
                            type="submit"
                            class="primary-button"
                        >
                            @lang('admin::app.contacts.persons.edit.save-btn')
                        </button>

                        {!! view_render_event('admin.persons.edit.save_button.after') !!}
                    </div>
                </div>
            </div>

            {{-- El aviso de SMD también de borde a borde; sus clases las reescribe smd-checker, por eso desde acá. --}}
            <div class="flex flex-col gap-4 max-lg:gap-0 max-lg:[&>#smd-result-banner]:-mx-3 max-lg:[&>#smd-result-banner]:rounded-none max-lg:[&>#smd-result-banner]:border-x-0 max-lg:[&>#smd-result-banner]:px-3">
                {!! view_render_event('admin.contacts.persons.edit.form_controls.before') !!}

                <!-- Sección 1: Datos del Paciente -->
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                    <div class="mb-4 flex items-center justify-between">
                        <h4 class="text-base font-bold dark:text-white">Datos del Paciente</h4>
                        {{-- Chip de estado SMD --}}
                        <div class="flex items-center gap-2">
                            <div id="smd-status-chip" class="hidden items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-all duration-200">
                                <span id="smd-status-dot" class="h-2 w-2 rounded-full"></span>
                                <span id="smd-status-text"></span>
                            </div>
                            @include('admin::contacts.persons.partials.smd-checker', [
                                'searchUrl' => route('admin.contacts.persons.search_smd'),
                                'mode'      => 'edit',
                                'person'    => $person,
                            ])
                        </div>
                    </div>

                    <x-admin::attributes
                        :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                            ['code', 'IN', ['name', 'job_title', 'emails', 'contact_numbers']],
                            'entity_type' => 'persons',
                        ])"
                        :custom-validations="[
                            'name' => ['min:2', 'max:100'],
                            'job_title' => ['max:100'],
                        ]"
                        :entity="$person"
                    />
                </div>

                <!-- Banner resultado SMD -->
                <div id="smd-result-banner" class="hidden rounded-lg border px-4 py-3 text-sm transition-all duration-300">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span id="smd-banner-icon" class="mt-0.5 shrink-0"></span>
                            <div>
                                <p id="smd-banner-title" class="font-semibold"></p>
                                <p id="smd-banner-detail" class="mt-0.5 text-xs opacity-80"></p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button" class="text-xs opacity-60 hover:opacity-100 focus:outline-none max-lg:flex max-lg:h-11 max-lg:w-11 max-lg:items-center max-lg:justify-center" onclick="smdDismiss()" aria-label="Cerrar">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Comparación formulario ↔ SMD: el operador elige qué datos usar -->
                @include('admin::contacts.persons.partials.smd-comparar')

                <!-- Sección 2: Datos del Seguro -->
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-base font-bold dark:text-white">Datos del Seguro</h4>
                        
                        <!-- Integración del Validador de Seguro -->
                        @php
                            $seguroAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)->findOneByField('code', 'seguro_paciente');
                            $seguroId = $person->getCustomAttributeValue($seguroAttr);
                            $seguroLabel = app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->getAttributeLabel($seguroId, $seguroAttr);
                        @endphp
                        @include ('admin::contacts.persons.view.insurance-verify', ['currentInsurance' => $seguroLabel])
                    </div>
                    
                    @php
                        // Orden: CI y Seguro primero; Estado de seguro al final (lo auto-rellena
                        // el botón de verificación, aunque queda editable).
                        $insuranceFieldOrder = ['ci_paciente', 'seguro_paciente', 'estado_seguro_paciente'];

                        $insuranceAttributes = app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                            ['code', 'IN', $insuranceFieldOrder],
                            'entity_type' => 'persons',
                        ])->sortBy(fn ($attribute) => array_search($attribute->code, $insuranceFieldOrder))->values();
                    @endphp

                    <x-admin::attributes
                        :custom-attributes="$insuranceAttributes"
                        :entity="$person"
                    />
                </div>

                <!-- Sección 3: Equipo de Atención -->
                <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                    <h4 class="mb-4 text-base font-bold dark:text-white">Equipo de Atención</h4>
                    
                    <x-admin::attributes
                        :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                            ['code', 'IN', ['person_doctor', 'user_id']],
                            'entity_type' => 'persons',
                        ])"
                        :entity="$person"
                    />
                </div>

                {!! view_render_event('admin.contacts.persons.edit.form_controls.after') !!}
            </div>
        </div>
    </x-admin::form>

    {!! view_render_event('admin.persons.edit.form.after') !!}

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-organization-template"
        >
            <div>
                <x-admin::attributes
                    :custom-attributes="app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                        ['code', 'IN', ['organization_id']],
                        'entity_type' => 'persons',
                    ])"
                    :entity="$person"
                />

                <template v-if="organizationName">
                    <x-admin::form.control-group.control
                        type="hidden"
                        name="organization_name"
                        v-model="organizationName"
                    />
                </template>
            </div>
        </script>

        <script type="module">
            app.component('v-organization', {
                template: '#v-organization-template',

                data() {
                    return {
                        organizationName: null,
                    };
                },

                methods: {
                    handleLookupAdded(event) {
                        this.organizationName = event?.name || null;
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
