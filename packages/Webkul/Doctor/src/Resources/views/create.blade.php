<x-admin::layouts>
    <x-slot:title>
        {{ isset($doctor) ? 'Editar Doctor' : 'Registrar Doctor' }}
    </x-slot>

    <x-admin::form
        :action="isset($doctor) ? route('admin.doctor.update', $doctor->id) : route('admin.doctor.store')"
        method="{{ isset($doctor) ? 'PUT' : 'POST' }}"
        encType="multipart/form-data"
    >
        <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <div class="text-xl font-bold dark:text-white">
                        {{ isset($doctor) ? 'Editar Doctor' : 'Registrar Doctor' }}
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5">
                    <button type="submit" class="primary-button">
                        Guardar
                    </button>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="grid grid-cols-2 gap-4 max-lg:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Correo electrónico
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="email"
                            id="email"
                            name="email"
                            :label="trans('Correo electrónico')"
                            :placeholder="trans('Ej.: doctor@odontoking.com')"
                             :value="old('email', isset($doctor) ? $doctor->email : '')"
                            v-debounce="500"
                        />

                        <x-admin::form.control-group.error control-name="email" />
                    </x-admin::form.control-group>

                    {{-- WhatsApp number. ShareMeData never sends it, so it is
                         loaded here by hand; it is what lets the WhatsApp module
                         open a conversation with the doctor. --}}
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            WhatsApp
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="phone"
                            name="phone"
                            :label="trans('WhatsApp')"
                            :placeholder="trans('Ej.: 78960835 o 59178960835')"
                            :value="old('phone', isset($doctor) ? $doctor->phone : '')"
                        />

                        <x-admin::form.control-group.error control-name="phone" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            ID de ShareMeData (Hexadecimal)
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="unique_id"
                            name="unique_id"
                            :label="trans('ID de ShareMeData')"
                            :placeholder="trans('Ej.: 69bd9a9b7549b10008e0acfa')"
                            :value="old('unique_id', isset($doctor) ? $doctor->unique_id : '')"
                            rules="max:100"
                            v-debounce="500"
                            disabled="true"
                        />

                        <x-admin::form.control-group.error control-name="unique_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Nombres completos
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="name"
                            name="name"
                            rules="required|min:2|max:150"
                            :label="trans('Nombres completos')"
                            :placeholder="trans('Nombres completos')"
                             :value="old('name', isset($doctor) ? $doctor->name : '')"
                            v-debounce="500"
                        />

                        <x-admin::form.control-group.error control-name="name" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Especialidades (Mantén presionado Ctrl/Cmd para seleccionar varias)
                        </x-admin::form.control-group.label>

                        @php
                            $selectedSpecialties = old('specialties', isset($doctor) ? $doctor->specialties->pluck('id')->toArray() : []);
                        @endphp

                        <select
                            name="specialties[]"
                            id="specialties"
                            class="flex min-h-[100px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                            multiple
                        >
                            @foreach ($specialties as $sp)
                                <option
                                    value="{{ $sp->id }}"
                                    {{ in_array($sp->id, $selectedSpecialties) ? 'selected' : '' }}
                                >
                                    {{ $sp->name }}
                                </option>
                            @endforeach
                        </select>

                        <x-admin::form.control-group.error control-name="specialties" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            Estado
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="switch"
                            id="is_active"
                            name="is_active"
                            :checked="old('is_active', isset($doctor) ? ($doctor->is_active ? '1' : '') : '1')"
                        />
                    </x-admin::form.control-group>
                </div>

                <!-- Age Range Section Integrated -->
                <div class="mt-4 border-t pt-4 dark:border-gray-800">
                    <v-age-range-selector :doctor='@json(isset($doctor) ? $doctor : null)'></v-age-range-selector>
                </div>

                <!-- Other Custom Attributes Integrated -->
                @php
                    $attributes = app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere(['entity_type' => 'doctors', 'quick_add' => 1]);
                    $otherAttributes = $attributes->whereNotIn('code', ['age_range_min', 'age_range_max', 'age_range_notes']);
                @endphp

                @if (count($otherAttributes))
                    <div class="mt-4 border-t pt-4 dark:border-gray-800">
                        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                            Información adicional
                        </p>

                        <x-admin::attributes
                            :custom-attributes="$otherAttributes"
                            :entity="isset($doctor) ? $doctor : null"
                        />
                    </div>
                @endif
            </div>
        </div>
    </x-admin::form>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-age-range-selector-template">
            <div>
                <div class="flex items-center justify-between mb-4">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">
                        Rango de edad de pacientes
                    </p>

                    <label class="flex cursor-pointer items-center gap-2">
                        <input 
                            type="checkbox" 
                            class="peer hidden" 
                            v-model="allAges"
                            @change="toggleAllAges"
                        >
                        <span class="icon-checkbox-outline peer-checked:icon-checkbox-select text-2xl text-gray-600 peer-checked:text-brandColor transition-all"></span>
                        <span class="text-sm font-medium text-gray-600 dark:text-gray-300">Atiende todas las edades (0-99)</span>
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-4 mb-4 max-lg:grid-cols-1">
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-gray-600 dark:text-gray-300">
                            Edad mínima
                        </label>

                        <v-field
                            name="age_range_min"
                            v-model="ageMin"
                            v-slot="{ field }"
                        >
                            <input
                                type="number"
                                name="age_range_min"
                                v-bind="field"
                                min="0"
                                max="99"
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                :readonly="allAges"
                                :class="{'bg-gray-100 dark:bg-gray-800 cursor-not-allowed': allAges}"
                            >
                        </v-field>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-gray-600 dark:text-gray-300">
                            Edad máxima
                        </label>

                        <v-field
                            name="age_range_max"
                            v-model="ageMax"
                            v-slot="{ field }"
                        >
                            <input
                                type="number"
                                name="age_range_max"
                                v-bind="field"
                                min="0"
                                max="99"
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                :readonly="allAges"
                                :class="{'bg-gray-100 dark:bg-gray-800 cursor-not-allowed': allAges}"
                            >
                        </v-field>
                    </div>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium text-gray-600 dark:text-gray-300">
                        Observaciones de atención (Rango de edad)
                    </label>

                    <v-field
                        name="age_range_notes"
                        v-model="notes"
                        v-slot="{ field }"
                    >
                        <textarea
                            name="age_range_notes"
                            v-bind="field"
                            class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                            placeholder="Ej: Solo atiende niños acompañados de sus padres o adultos mayores"
                        ></textarea>
                    </v-field>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-age-range-selector', {
                template: '#v-age-range-selector-template',
                props: ['doctor'],
                data() {
                    return {
                        ageMin: this.doctor ? this.doctor.age_range_min : 0,
                        ageMax: this.doctor ? this.doctor.age_range_max : 99,
                        notes: this.doctor ? this.doctor.age_range_notes : '',
                        allAges: false
                    }
                },
                mounted() {
                    if (this.ageMin == 0 && this.ageMax == 99) {
                        this.allAges = true;
                    }
                },
                methods: {
                    toggleAllAges() {
                        if (this.allAges) {
                            this.ageMin = 0;
                            this.ageMax = 99;
                        }
                    }
                },
                watch: {
                    ageMin(val) {
                        if (val != 0 || this.ageMax != 99) {
                            this.allAges = false;
                        }
                    },
                    ageMax(val) {
                        if (val != 99 || this.ageMin != 0) {
                            this.allAges = false;
                        }
                    }
                }
            });
        </script>
    @endPushOnce

</x-admin::layouts>
