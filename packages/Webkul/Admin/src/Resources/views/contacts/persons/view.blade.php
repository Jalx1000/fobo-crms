<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.contacts.persons.view.title', ['name' => $person->name])
    </x-slot>

    <!-- Content -->
    <div class="flex gap-4 max-lg:flex-wrap">
        <!-- Left Panel -->
        {!! view_render_event('admin.contact.persons.view.left.before', ['person' => $person]) !!}

        <div class="max-lg:min-w-full max-lg:max-w-full [&>div:last-child]:border-b-0 lg:sticky lg:top-[73px] flex min-w-[394px] max-w-[394px] flex-col self-start rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
            <!-- Person Information -->
            <div class="flex w-full flex-col gap-2 border-b border-gray-200 p-4 dark:border-gray-800">
                <!-- Breadcrumbs -->
                <div class="flex items-center justify-between">
                    <x-admin::breadcrumbs
                        name="contacts.persons.view"
                        :entity="$person"
                    />
                </div>

                {!! view_render_event('admin.contact.persons.view.tags.before', ['person' => $person]) !!}

                <!-- Tags -->
                <x-admin::tags
                    :attach-endpoint="route('admin.contacts.persons.tags.attach', $person->id)"
                    :detach-endpoint="route('admin.contacts.persons.tags.detach', $person->id)"
                    :added-tags="$person->tags"
                />

                {!! view_render_event('admin.contact.persons.view.tags.after', ['person' => $person]) !!}

                
                <!-- Title -->
                <div class="mb-4 flex flex-col gap-0.5">
                    {!! view_render_event('admin.contact.persons.view.title.before', ['person' => $person]) !!}

                    <h3 class="text-lg font-bold dark:text-white">
                        {{ $person->name }}
                    </h3>

                    <p class="dark:text-white">
                        {{ $person->job_title }}
                    </p>

                    {!! view_render_event('admin.contact.persons.view.title.after', ['person' => $person]) !!}
                </div>

                <!-- Activity Actions -->
                <div class="flex flex-wrap gap-2">
                    {!! view_render_event('admin.contact.persons.view.actions.before', ['person' => $person]) !!}

                    <!-- Mail Activity Action -->
                    {{-- <x-admin::activities.actions.mail
                        :entity="$person"
                        entity-control-name="person_id"
                    /> --}}

                    <!-- File Activity Action -->
                    <x-admin::activities.actions.file
                        :entity="$person"
                        entity-control-name="person_id"
                    />

                    <!-- Note Activity Action -->
                    <x-admin::activities.actions.note
                        :entity="$person"
                        entity-control-name="person_id"
                    />

                    <!-- Activity Action -->
                    <x-admin::activities.actions.activity
                        :entity="$person"
                        entity-control-name="person_id"
                    />

                    <!-- Insurance Verification (Tessa: oculto, no usa seguros) -->
                    @if (config('tessa.features.insurance_smd'))
                        @php
                            $seguroAttr = app(\Webkul\Attribute\Repositories\AttributeRepository::class)->findOneByField('code', 'seguro_paciente');
                            $seguroId = $person->getCustomAttributeValue($seguroAttr);
                            $seguroLabel = app(\Webkul\Attribute\Repositories\AttributeValueRepository::class)->getAttributeLabel($seguroId, $seguroAttr);
                        @endphp
                        @include ('admin::contacts.persons.view.insurance-verify', ['currentInsurance' => $seguroLabel])
                    @endif

                    {!! view_render_event('admin.contact.persons.view.actions.after', ['person' => $person]) !!}
                </div>

                {{-- ShareMeData sync status & button (Tessa: oculto, no usa SMD) --}}
                @if (config('tessa.features.insurance_smd'))
                <div class="flex items-center gap-2 mt-1">
                    <span class="text-xs font-medium text-gray-500">ShareMeData:</span>

                    @if($person->smd_patient_id)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                            </svg>
                            Sincronizado
                        </span>
                        <span class="text-xs text-gray-400 font-mono">{{ $person->smd_patient_id }}</span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92z"/>
                            </svg>
                            No sincronizado
                        </span>
                    @endif
                </div>

                <div id="smd-sync-{{ $person->id }}">
                    <v-smd-sync
                        person-id="{{ $person->id }}"
                        :initial-smd-id="{{ json_encode($person->smd_patient_id) }}"
                        sync-url="{{ route('admin.contacts.persons.sync_smd', $person->id) }}"
                    />
                </div>
                @endif

            </div>

            <!-- Estado de cobertura por aseguradora (Tessa: oculto) -->
            @if (config('tessa.features.insurance_smd'))
                @include ('admin::contacts.persons.view.insurance-statuses')
            @endif

            <!-- Person Attributes -->
            @include ('admin::contacts.persons.view.attributes')

            <!-- Contact Organization -->
            @include ('admin::contacts.persons.view.organization')
        </div>

        {!! view_render_event('admin.contact.persons.view.left.after', ['person' => $person]) !!}

        <!-- Right Panel -->
        <div class="flex w-full flex-col gap-4 rounded-lg">
            {!! view_render_event('admin.contact.persons.view.right.before', ['person' => $person]) !!}

            <!-- Stages Navigation -->
            @php
                $extraTypes = [
                    ['name' => 'whatsapp', 'label' => 'WhatsApp'],
                    ['name' => 'historial', 'label' => 'Historial IA'],
                ];
                // Tessa: la pestaña "Seguro & SMD" solo si la feature está activa.
                if (config('tessa.features.insurance_smd')) {
                    $extraTypes[] = ['name' => 'smd', 'label' => 'Seguro & SMD'];
                }
            @endphp
            <x-admin::activities :endpoint="route('admin.contacts.persons.activities.index', $person->id)" :extra-types="$extraTypes">
                {{-- WhatsApp Inbox --}}
                <x-slot:whatsapp>
                    @include('whatsapp::inbox')
                </x-slot>

                {{-- Historial IA --}}
                <x-slot:historial>
                    @include('admin::leads.view.historial-ia', [
                        'entityEmail' => ($person->emails[0]['value'] ?? ($person->email ?? null)),
                        'entityName'  => $person->name
                    ])
                </x-slot>

                {{-- Seguro & SMD --}}
                @if (config('tessa.features.insurance_smd'))
                <x-slot:smd>
                    @include('admin::contacts.persons.view.smd-insurance', [
                        'person'             => $person,
                        'smdUrl'             => route('admin.contacts.persons.smd_data', $person->id),
                        'linkUrl'            => route('admin.contacts.persons.link_smd', $person->id),
                        'syncUrl'            => route('admin.contacts.persons.sync_smd', $person->id),
                        'searchUrl'          => route('admin.contacts.persons.search_smd'),
                        'insuranceStatusUrl' => route('admin.contacts.persons.insurance_status', $person->id),
                        'verifyUrl'          => route('admin.contacts.persons.verify_insurance', $person->id),
                        'clearCacheUrl'      => route('admin.contacts.persons.clear_insurance_cache', $person->id),
                    ])
                </x-slot>
                @endif
            </x-admin::activities>

            {!! view_render_event('admin.contact.persons.view.right.after', ['person' => $person]) !!}
        </div>
    </div>
    @push('scripts')
    <script>
    app.component('v-smd-sync', {
        props: ['personId', 'initialSmdId', 'syncUrl'],
        data() {
            return {
                smdId:   this.initialSmdId,
                loading: false,
                message: null,
                error:   null,
            };
        },
        template: `
            <div class="mt-1">
                <button
                    v-if="!smdId"
                    @click="syncPatient"
                    :disabled="loading"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50"
                >
                    <span v-if="loading">Sincronizando...</span>
                    <span v-else>Sincronizar con SMD</span>
                </button>
                <div v-if="message" class="mt-1 text-xs text-green-600">@{{ message }}</div>
                <div v-if="error"   class="mt-1 text-xs text-red-600">@{{ error }}</div>
                <div v-if="smdId && !initialSmdId" class="mt-1 text-xs text-gray-400 font-mono">
                    ID SMD: @{{ smdId }}
                </div>
            </div>
        `,
        methods: {
            async syncPatient() {
                this.loading = true;
                this.message = null;
                this.error   = null;
                try {
                    const { data } = await this.$axios.post(this.syncUrl);
                    this.smdId   = data.smd_patient_id;
                    this.message = data.message || 'Sincronizado correctamente';
                } catch (err) {
                    this.error = err.response?.data?.message || 'Error al sincronizar';
                } finally {
                    this.loading = false;
                }
            },
        },
    });
    </script>
    @endpush
</x-admin::layouts>
