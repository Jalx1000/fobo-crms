<x-admin::layouts>
    <x-slot:title>
        {{ isset($shift) ? 'Editar Turno' : 'Registrar Turno' }}
    </x-slot>

    <x-admin::form
        :action="isset($shift) ? route('admin.schedules.update', $shift->id) : route('admin.schedules.store')"
        method="{{ isset($shift) ? 'PUT' : 'POST' }}"
    >
        {{-- Celular: de borde a borde, sin el gris de alrededor (como Servicios y Pacientes). --}}
        <div class="flex flex-col gap-4 max-lg:gap-0">
            <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-1">
                        <x-admin::layouts.volver listado="admin.schedules.index" />

                        <div class="text-xl font-bold dark:text-white">
                            {{ isset($shift) ? 'Editar Turno' : 'Registrar Turno' }}
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-x-2.5 max-lg:flex-wrap max-lg:gap-y-2">
                    <a href="{{ route('admin.schedules.index') }}" class="secondary-button">
                        Cancelar
                    </a>

                    <button type="submit" class="primary-button">
                        Guardar
                    </button>
                </div>
            </div>

            <div class="box-shadow rounded-lg border border-gray-200 bg-white p-4 max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900">
                <div class="grid grid-cols-2 gap-4 max-lg:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Doctor
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="select"
                            id="doctor_id"
                            name="doctor_id"
                            rules="required|integer"
                            :label="trans('Doctor')"
                            :placeholder="trans('Selecciona un doctor')"
                        >
                            @php
                                $selectedDoctor = old('doctor_id', isset($shift) ? $shift->doctor_id : (request()->get('doctor_id') ?? ''));
                            @endphp

                            <option value="">Selecciona un doctor</option>

                            @foreach ($doctors as $doc)
                                <option
                                    value="{{ $doc->id }}"
                                    {{ (string)$selectedDoctor === (string)$doc->id ? 'selected' : '' }}
                                >
                                    {{ $doc->name }}
                                </option>
                            @endforeach
                        </x-admin::form.control-group.control>

                        <x-admin::form.control-group.error control-name="doctor_id" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Fecha
                        </x-admin::form.control-group.label>

                        <x-admin::flat-picker.date class="!w-full" ::allow-input="false">
                            <input
                                type="date"
                                id="date"
                                name="date"
                                value="{{ old('date', isset($shift) ? $shift->date?->toDateString() : (request()->get('date') ?? '')) }}"
                                class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                            />
                        </x-admin::flat-picker.date>

                        <x-admin::form.control-group.error control-name="date" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Hora de inicio
                        </x-admin::form.control-group.label>

                        <input
                            type="time"
                            id="start_time"
                            name="start_time"
                            value="{{ old('start_time', isset($shift) ? substr($shift->start_time, 0, 5) : '') }}"
                            class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        />

                        <x-admin::form.control-group.error control-name="start_time" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            Hora de fin
                        </x-admin::form.control-group.label>

                        <input
                            type="time"
                            id="end_time"
                            name="end_time"
                            value="{{ old('end_time', isset($shift) ? substr($shift->end_time, 0, 5) : '') }}"
                            class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        />

                        <x-admin::form.control-group.error control-name="end_time" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="col-span-2 max-lg:col-span-1">
                        <x-admin::form.control-group.label>
                            Notas
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="textarea"
                            id="notes"
                            name="notes"
                            :value="old('notes', isset($shift) ? $shift->notes : '')"
                            :label="trans('Notas')"
                            :placeholder="trans('Opcional')"
                        />

                        <x-admin::form.control-group.error control-name="notes" />
                    </x-admin::form.control-group>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
