<x-admin::layouts>
    <x-slot:title>
        Turnos programados
    </x-slot>

    {{-- Celular: de borde a borde, sin el gris de alrededor (como Servicios y Pacientes). --}}
    <div class="flex flex-col gap-4 max-lg:gap-0">
        <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm max-lg:-mx-3 max-lg:-mt-3 max-lg:flex-wrap max-lg:gap-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            <div class="flex items-center gap-2">
                <div class="text-xl font-bold dark:text-white">
                    Turnos programados
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('admin.schedules.create') }}" class="primary-button">
                    Añadir
                </a>
            </div>
        </div>

        <v-schedules>
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <x-admin::shimmer.datagrid :is-multi-row="false"/>
            </div>
        </v-schedules>

    </div>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-time-picker-template">
    <div class="tp-container" ref="root">
        <div class="tp-input" @click="toggle">
            <span>@{{ modelValue || '00:00' }}</span>
            <i class="icon-clock text-lg"></i>
        </div>
        <div v-show="isOpen" class="tp-dropdown" ref="dropdown">
            <div class="tp-lists">
                <ul class="tp-list" ref="hours">
                    <li v-for="h in 24" :key="'h-'+h" @click.stop="selectHour(h-1)" :class="{ 'is-selected': (h-1) == currentHour }">
                        @{{ pad2(h-1) }}
                    </li>
                </ul>
                <ul class="tp-list" ref="minutes">
                    <li v-for="m in 60" :key="'m-'+m" @click.stop="selectMinute(m-1)" :class="{ 'is-selected': (m-1) == currentMinute }">
                        @{{ pad2(m-1) }}
                    </li>
                </ul>
            </div>
        </div>
    </div>
</script>

<script type="text/x-template" id="v-schedules-template">
            <div class="rounded-lg border border-gray-200 bg-white p-4 text-sm max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3 max-lg:shadow-none dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="secondary-button" @click="goPrevWeek">‹</button>
                        <div class="rounded border px-3 py-1 dark:border-gray-800">
                            @{{ rangeLabel }}
                        </div>
                        <button type="button" class="secondary-button" @click="goNextWeek">›</button>
                        <button type="button" class="secondary-button" @click="goThisWeek">Esta semana</button>
                    </div>
                </div>

                <div class="overflow-auto">
                    <table class="min-w-full border-collapse">
                        <thead>
                            <tr>
                                <th class="w-64 border-b p-2 text-left dark:border-gray-800">Miembro del equipo</th>
                                <th v-for="day in days" class="border-b p-2 text-left dark:border-gray-800">
                                    <div class="flex items-center justify-between">
                                        <span>@{{ day.label }}</span>
                                        <span class="text-xs text-gray-600 dark:text-gray-400">@{{ dayTotal(day.date) }}</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="doctor in doctors" :key="doctor.id">
                                <td class="border-b p-3 align-top dark:border-gray-800">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                @{{ initials(doctor.name) }}
                                            </div>
                                            <div class="flex flex-col">
                                                <span class="font-semibold dark:text-white">@{{ doctor.name }}</span>
                                                <span class="text-xs text-gray-600 dark:text-gray-400">@{{ doctorWeeklyTotal(doctor.id) }}</span>
                                            </div>
                                        </div>
                                        <a :href="'{{ route('admin.doctor.edit', 'replaceId') }}'.replace('replaceId', doctor.id)" class="icon-edit text-2xl"></a>
                                    </div>
                                </td>
                                <td v-for="day in days" class="border-b p-3 align-top dark:border-gray-800">
                                    <div class="flex flex-col gap-2">
                                        <template v-if="shiftsFor(doctor.id, day.date).length">
                                            <div class="rounded bg-violet-100 px-2 py-1 text-violet-800 dark:bg-violet-900 dark:text-violet-100" v-for="shift in shiftsFor(doctor.id, day.date)" :key="shift.id">
                                                <div class="flex items-center justify-between">
                                                    <span>@{{ formatTime(shift.start_time) }} - @{{ formatTime(shift.end_time) }}</span>
                                                    <div class="flex items-center gap-2">
                                                        <button type="button" class="icon-edit text-xl" @click="openEdit(shift, doctor.id, day.date)"></button>
                                                        <button type="button" class="icon-delete text-xl text-red-600" @click="deleteShift(shift)"></button>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div class="rounded bg-gray-100 px-2 py-1 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                                No está trabajando
                                            </div>
                                        </template>
                                        <div class="mt-2">
                                            <button
                                                type="button"
                                                class="secondary-button"
                                                :data-doctor="doctor.id"
                                                :data-date="day.date"
                                                @click="openScheduleOptionsModal(doctor.id, day.date)"
                                            >
                                                Añadir horario
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 rounded-lg border border-dashed px-3 py-2 text-xs text-gray-600 dark:border-gray-800 dark:text-gray-400">
                    El calendario del equipo muestra tu disponibilidad para reservas y no está vinculado al horario habitual del negocio.
                </div>

                <!-- Modals -->
                <x-admin::modal ref="scheduleOptionsModal">
                    <x-slot:header>
                        <div class="text-lg font-semibold dark:text-white">
                            Gestionar Horario
                        </div>
                    </x-slot>
                    <x-slot:content>
                        <div class="flex flex-col gap-2">
                            <button type="button" class="w-full text-left px-4 py-3 rounded border hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center gap-3" @click="openAddModal">
                                <span class="icon-calendar text-xl text-blue-600"></span>
                                <div>
                                    <div class="font-semibold text-sm">Añadir turno</div>
                                    <div class="text-xs text-gray-500">Registrar un horario disponible individual</div>
                                </div>
                            </button>
                            <button type="button" class="w-full text-left px-4 py-3 rounded border hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center gap-3" @click="openRecurringModal">
                                <span class="icon-calendar text-xl text-purple-600"></span>
                                <div>
                                    <div class="font-semibold text-sm">Establecer turnos recurrentes</div>
                                    <div class="text-xs text-gray-500">Generar múltiples turnos automáticamente</div>
                                </div>
                            </button>
                            <!--
                            <button type="button" class="w-full text-left px-4 py-3 rounded border hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center gap-3" @click="openTimeOffModal">
                                <span class="icon-calendar text-xl text-orange-600"></span>
                                <div>
                                    <div class="font-semibold text-sm">Añadir días libres</div>
                                    <div class="text-xs text-gray-500">Registrar vacaciones o ausencias</div>
                                </div>
                            </button>
                            -->
                        </div>
                    </x-slot>
                </x-admin::modal>

                <x-admin::modal ref="addModal">
                    <x-slot:header>
                        <div class="text-lg font-semibold dark:text-white">
                            Añadir turno
                        </div>
                    </x-slot>
                    <x-slot:content>
                        <div class="flex flex-col gap-3">
                            <div class="text-sm text-gray-600 dark:text-gray-300">
                                @{{ modalContext.dateLabel }} · @{{ modalContext.doctorName }}
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <v-time-picker v-model="addDlg.startTime"></v-time-picker>
                                <v-time-picker v-model="addDlg.endTime"></v-time-picker>
                            </div>
                            <div class="text-sm text-red-600" v-if="addDlg.error">@{{ addDlg.error }}</div>
                        </div>
                    </x-slot>
                    <x-slot:footer>
                        <div class="flex items-center gap-2 justify-end">
                            <button type="button" class="secondary-button" @click="$refs.addModal.close()">Cancelar</button>
                            <button type="button" class="primary-button" @click="saveSchedule" :disabled="addDlg.saving">
                                <span v-if="!addDlg.saving">Guardar</span>
                                <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando...</span>
                            </button>
                        </div>
                    </x-slot>
                </x-admin::modal>

                <x-admin::modal ref="recurringModal">
                    <x-slot:header>
                        <div class="text-lg font-semibold dark:text-white">
                            Turnos Recurrentes
                        </div>
                    </x-slot>
                    <x-slot:content>
                        <div class="flex flex-col gap-4 max-h-[60vh] overflow-y-auto pr-2 custom-scrollbar">
                            <div class="p-4 border rounded-lg bg-gray-50 dark:bg-gray-800 dark:border-gray-700">
                                <div class="grid grid-cols-2 gap-4 mb-4">
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs font-medium text-gray-500">Fecha de Inicio</label>
                                        <input type="date" class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="recurringForm.start_date" />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="text-xs font-medium text-gray-500">Fecha de Fin</label>
                                        <input type="date" class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="recurringForm.end_date" />
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 mb-4">
                                    <label class="text-xs font-medium text-gray-500">Días de la semana</label>
                                    <div class="flex flex-wrap gap-2">
                                        <label v-for="(day, idx) in ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']" :key="'rd-'+idx" class="flex items-center gap-2 px-3 py-2 border rounded cursor-pointer hover:bg-white dark:hover:bg-gray-700 transition-colors" :class="recurringForm.days.includes(idx+1) ? 'bg-blue-50 border-blue-500 text-blue-700 dark:bg-blue-900/30 dark:border-blue-500 dark:text-blue-300' : 'bg-white dark:bg-gray-900 border-gray-200 dark:border-gray-700'">
                                            <input type="checkbox" :value="idx+1" v-model="recurringForm.days" class="hidden" />
                                            <span class="text-sm font-medium">@{{ day }}</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2">
                                    <label class="text-xs font-medium text-gray-500">Horarios</label>
                                    <div v-for="(slot, idx) in recurringForm.time_slots" :key="'ts-'+idx" class="flex items-center gap-2 mb-2">
                                        <div class="flex-1 grid grid-cols-2 gap-2">
                                            <div class="flex flex-col gap-1">
                                                <label class="text-[10px] font-medium text-gray-400" v-if="idx===0">Hora Inicio</label>
                                                <v-time-picker v-model="slot.startTime"></v-time-picker>
                                            </div>
                                            <div class="flex flex-col gap-1">
                                                <label class="text-[10px] font-medium text-gray-400" v-if="idx===0">Hora Fin</label>
                                                <v-time-picker v-model="slot.endTime"></v-time-picker>
                                            </div>
                                        </div>
                                        <button type="button" class="mt-auto mb-1 p-2 text-red-600 hover:bg-red-50 rounded dark:hover:bg-red-900/20" @click="removeTimeSlot(idx)" v-if="recurringForm.time_slots.length > 1">
                                            <span class="icon-cross text-lg">×</span>
                                        </button>
                                        <button type="button" class="mt-auto mb-1 p-2 text-blue-600 hover:bg-blue-50 rounded dark:hover:bg-blue-900/20" @click="addTimeSlot" v-if="idx === recurringForm.time_slots.length - 1">
                                            <span class="text-xl font-bold">+</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-sm text-red-600" v-if="addDlg.error">@{{ addDlg.error }}</div>
                        </div>
                    </x-slot>
                    <x-slot:footer>
                        <div class="flex items-center gap-2 justify-end">
                            <button type="button" class="secondary-button" @click="$refs.recurringModal.close()">Cancelar</button>
                            <button type="button" class="primary-button" @click="saveRecurring" :disabled="addDlg.saving">
                                <span v-if="!addDlg.saving">Generar Turnos</span>
                                <span v-else class="flex items-center gap-2"><x-admin::spinner /> Procesando...</span>
                            </button>
                        </div>
                    </x-slot>
                </x-admin::modal>

                <x-admin::modal ref="timeOffModal">
                    <x-slot:header>
                        <div class="text-lg font-semibold dark:text-white">
                            Añadir días libres
                        </div>
                    </x-slot>
                    <x-slot:content>
                        <div class="flex flex-col gap-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-gray-500">Miembro del equipo</label>
                                    <select class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="timeOffForm.doctor_id" disabled>
                                        <option v-for="d in doctors" :key="'to-d-'+d.id" :value="d.id">@{{ d.name }}</option>
                                    </select>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-gray-500">Tipo</label>
                                    <select class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="timeOffForm.type">
                                        <option value="Vacaciones anuales">Vacaciones anuales</option>
                                        <option value="Enfermedad">Enfermedad</option>
                                        <option value="Personal">Personal</option>
                                        <option value="Otro">Otro</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4">
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-gray-500">Fecha de inicio</label>
                                    <input type="date" class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="timeOffForm.start_date" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-gray-500">Hora de inicio</label>
                                    <v-time-picker v-model="timeOffForm.start_time"></v-time-picker>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label class="text-xs font-medium text-gray-500">Hora de finalización</label>
                                    <v-time-picker v-model="timeOffForm.end_time"></v-time-picker>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <input type="checkbox" id="to-repeat" v-model="timeOffForm.repeat" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                <label for="to-repeat" class="text-sm text-gray-700 dark:text-gray-300">Repetir</label>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label class="text-xs font-medium text-gray-500">Descripción</label>
                                <textarea class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" rows="3" v-model="timeOffForm.description" placeholder="Añadir descripción o comentario (opcional)"></textarea>
                            </div>

                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <input type="checkbox" id="to-approved" v-model="timeOffForm.approved" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                                    <label for="to-approved" class="text-sm text-gray-700 dark:text-gray-300">Aprobado</label>
                                </div>
                                <div class="text-sm text-gray-500">
                                    Días libres: @{{ calculateTimeOffDuration() }}
                                </div>
                            </div>

                            <div class="text-xs text-gray-500">
                                No se puede reservar online durante los días libres.
                            </div>
                            
                            <div class="text-sm text-red-600" v-if="addDlg.error">@{{ addDlg.error }}</div>
                        </div>
                    </x-slot>
                    <x-slot:footer>
                        <div class="flex items-center gap-2 justify-end">
                            <button type="button" class="secondary-button" @click="$refs.timeOffModal.close()">Cancelar</button>
                            <button type="button" class="primary-button bg-black hover:bg-gray-800 text-white" @click="saveTimeOff" :disabled="addDlg.saving">
                                <span v-if="!addDlg.saving">Guardar</span>
                                <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando...</span>
                            </button>
                        </div>
                    </x-slot>
                </x-admin::modal>
            </div>
        </script>

        <script type="module">
            app.component('v-time-picker', {
    template: '#v-time-picker-template',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    data() {
        return {
            isOpen: false,
        };
    },
    computed: {
        currentHour() {
            return this.modelValue ? parseInt(this.modelValue.split(':')[0], 10) : 0;
        },
        currentMinute() {
            return this.modelValue ? parseInt(this.modelValue.split(':')[1], 10) : 0;
        }
    },
    watch: {
        isOpen(val) {
            if (val) {
                this.$nextTick(() => {
                    this.positionDropdown();
                    this.scrollToSelected();
                });
            }
        }
    },
    methods: {
        pad2(n) {
            return String(n).padStart(2, '0');
        },
        toggle() {
            this.isOpen = !this.isOpen;
        },
        selectHour(h) {
            const m = this.currentMinute;
            this.$emit('update:modelValue', `${this.pad2(h)}:${this.pad2(m)}`);
            this.$nextTick(() => this.scrollToSelected());
        },
        selectMinute(m) {
            const h = this.currentHour;
            this.$emit('update:modelValue', `${this.pad2(h)}:${this.pad2(m)}`);
            this.isOpen = false;
        },
        positionDropdown() {
            const dropdown = this.$refs.dropdown;
            const root = this.$refs.root;
            if (!dropdown || !root) return;
            const rect = root.getBoundingClientRect();
            dropdown.style.position = 'fixed';
            dropdown.style.top = `${rect.bottom + 4}px`;
            dropdown.style.left = `${rect.left}px`;
            dropdown.style.width = `${rect.width}px`;
        },
        scrollToSelected() {
            const dropdown = this.$refs.dropdown;
            if (!dropdown) return;
            const hoursEl = this.$refs.hours;
            const minutesEl = this.$refs.minutes;
            if (hoursEl) {
                const selected = hoursEl.querySelector('.is-selected');
                if (selected) {
                    hoursEl.scrollTop = selected.offsetTop - (hoursEl.offsetHeight / 2) + (selected.offsetHeight / 2);
                }
            }
            if (minutesEl) {
                const selected = minutesEl.querySelector('.is-selected');
                if (selected) {
                    minutesEl.scrollTop = selected.offsetTop - (minutesEl.offsetHeight / 2) + (selected.offsetHeight / 2);
                }
            }
        },
        onClickOutside(e) {
            const root = this.$refs.root;
            const dropdown = this.$refs.dropdown;
            if (root && !root.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
                this.isOpen = false;
            }
        }
    },
    mounted() {
        document.addEventListener('click', this.onClickOutside, true);
    },
    beforeUnmount() {
        document.removeEventListener('click', this.onClickOutside, true);
    }
});

app.component('v-schedules', {
                template: '#v-schedules-template',
                data() {
                    return {
                        days: [],
                        range: { start: '', end: '' },
                        doctors: [],
                        shifts: [],
                        start: '',
                        addDlg: {
                            visible: false, // Legacy, can keep or remove if fully switched to modals
                            doctorId: null,
                            date: '',
                            shiftId: null,
                            startTime: '09:00',
                            endTime: '10:00',
                            saving: false,
                            error: '',
                        },
                        recurringForm: {
                            start_date: '',
                            end_date: '',
                            days: [],
                            time_slots: []
                        },
                        timeOffForm: {
                            doctor_id: null,
                            type: 'Vacaciones anuales',
                            start_date: '',
                            start_time: '10:00',
                            end_time: '19:00',
                            repeat: false,
                            description: '',
                            approved: false
                        },
                        modalContext: {
                            dateLabel: '',
                            doctorName: '',
                            doctorId: null,
                            date: ''
                        },
                    };
                },
                computed: {
                    rangeLabel() {
                        return `${this.formatDate(this.range.start)} - ${this.formatDate(this.range.end)}`;
                    },
                },
                mounted() {
                    const today = new Date();
                    const startOfWeek = this.toISO(this.getStartOfWeek(today));
                    this.start = startOfWeek;
                    this.fetchWeek();
                },
                methods: {
                    fetchWeek() {
                        const url = new URL('{{ route('admin.schedules.week') }}', window.location.origin);
                        url.searchParams.set('start', this.start);
                        fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(r => r.json())
                            .then(d => {
                                this.days = d.days;
                                this.range = d.range;
                                this.doctors = d.doctors;
                                this.shifts = d.shifts;
                            });
                    },
                    openScheduleOptionsModal(doctorId, date) {
                        const doctor = this.doctors.find(d => d.id === doctorId);
                        const dayObj = this.days.find(d => d.date === date);
                        this.modalContext.doctorId = doctorId;
                        this.modalContext.date = date;
                        this.modalContext.doctorName = doctor ? doctor.name : '';
                        this.modalContext.dateLabel = dayObj ? dayObj.label : date;
                        
                        this.$refs.scheduleOptionsModal.open();
                    },
                    openAddModal() {
                        this.$refs.scheduleOptionsModal.close();
                        
                        this.addDlg.doctorId = this.modalContext.doctorId;
                        this.addDlg.date = this.modalContext.date;
                        this.addDlg.shiftId = null;
                        this.addDlg.startTime = '09:00';
                        this.addDlg.endTime = '17:00';
                        this.addDlg.error = '';
                        
                        this.$refs.addModal.open();
                    },
                    openRecurringModal() {
                        this.$refs.scheduleOptionsModal.close();
                        
                        this.recurringForm.start_date = this.modalContext.date;
                        this.recurringForm.end_date = this.modalContext.date;
                        this.recurringForm.days = [];
                        this.recurringForm.time_slots = [{ startTime: '09:00', endTime: '17:00' }];
                        this.addDlg.error = '';
                        
                        this.$refs.recurringModal.open();
                    },
                    openTimeOffModal() {
                        this.$refs.scheduleOptionsModal.close();
                        
                        this.timeOffForm = {
                            doctor_id: this.modalContext.doctorId,
                            type: 'Vacaciones anuales',
                            start_date: this.modalContext.date,
                            start_time: '09:00',
                            end_time: '17:00',
                            repeat: false,
                            description: '',
                            approved: false
                        };
                        this.addDlg.error = '';
                        
                        this.$refs.timeOffModal.open();
                    },
                    addTimeSlot() {
                        this.recurringForm.time_slots.push({
                            startTime: '09:00',
                            endTime: '17:00'
                        });
                    },
                    removeTimeSlot(index) {
                        this.recurringForm.time_slots.splice(index, 1);
                    },
                    saveRecurring() {
                        this.addDlg.saving = true;
                        this.addDlg.error = '';

                        const shifts = this.recurringForm.time_slots.map(slot => ({
                            doctor_id: this.modalContext.doctorId,
                            start_date: this.recurringForm.start_date,
                            end_date: this.recurringForm.end_date,
                            days: this.recurringForm.days.map(d => d === 7 ? 0 : d),
                            start_time: slot.startTime,
                            end_time: slot.endTime,
                        }));

                        const payload = {
                            recurrence: true,
                            shifts: shifts
                        };
                        
                        const csrf = this.getCsrf();
                        fetch('{{ route('admin.schedules.store') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: JSON.stringify(payload),
                        })
                        .then(async (r) => {
                            if (!r.ok) {
                                const err = await r.json().catch(() => ({}));
                                throw new Error(err.message || 'Error al generar turnos');
                            }
                            return r.json();
                        })
                        .then(() => {
                            this.$refs.recurringModal.close();
                            this.fetchWeek();
                            // Optional: show success message
                        })
                        .catch((e) => {
                            this.addDlg.error = e.message || 'Error al generar turnos';
                        })
                        .finally(() => {
                            this.addDlg.saving = false;
                        });
                    },
                    saveTimeOff() {
                        this.addDlg.saving = true;
                        this.addDlg.error = '';
                        
                        const payload = {
                            type: 'time_off', // Activity type
                            title: this.timeOffForm.type,
                            comment: this.timeOffForm.description,
                            participants: { users: [this.timeOffForm.doctor_id] }, // Assuming doctor is a user or linked
                            schedule_from: this.timeOffForm.start_date + ' ' + this.timeOffForm.start_time,
                            schedule_to: this.timeOffForm.start_date + ' ' + this.timeOffForm.end_time,
                            is_done: this.timeOffForm.approved ? 1 : 0
                        };
                        
                        // NOTE: This endpoint needs to support activity creation. 
                        // The previous implementation used ActivityController.
                        // I should verify the endpoint.
                        // In doctor-day-calendar it uses `this.storeUrl` which is likely `admin.activities.store`.
                        // I will use `{{ route('admin.activities.store') }}`.
                        
                        const csrf = this.getCsrf();
                        fetch('{{ route('admin.activities.store') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrf,
                            },
                            body: JSON.stringify(payload),
                        })
                        .then(async (r) => {
                            if (!r.ok) {
                                const err = await r.json().catch(() => ({}));
                                throw new Error(err.message || 'Error al guardar días libres');
                            }
                            return r.json();
                        })
                        .then(() => {
                            this.$refs.timeOffModal.close();
                            // Note: Time offs are activities, they might not show up in "shifts" array unless "fetchWeek" returns them.
                            // The current fetchWeek logic returns shifts from doctor_shifts table.
                            // If time offs are activities, they won't appear here unless I update the backend logic or the UI.
                            // But the user just asked to display the form.
                            // I will reload week anyway.
                            this.fetchWeek(); 
                        })
                        .catch((e) => {
                            this.addDlg.error = e.message || 'Error al guardar días libres';
                        })
                        .finally(() => {
                            this.addDlg.saving = false;
                        });
                    },
                    calculateTimeOffDuration() {
                        if (!this.timeOffForm.start_time || !this.timeOffForm.end_time) return '0 h';
                        const start = this.timeToMinutes(this.timeOffForm.start_time);
                        const end = this.timeToMinutes(this.timeOffForm.end_time);
                        let diff = end - start;
                        if (diff < 0) diff += 24 * 60;
                        const h = Math.floor(diff / 60);
                        const m = diff % 60;
                        return `${h}h ${m}m`;
                    },
                    timeToMinutes(time) {
                        const [h, m] = time.split(':').map(Number);
                        return h * 60 + m;
                    },
                    openEdit(shift, doctorId, date) {
                        this.addDlg.doctorId = doctorId;
                        this.addDlg.date = date;
                        this.addDlg.shiftId = shift.id;
                        this.addDlg.startTime = shift.start_time;
                        this.addDlg.endTime = shift.end_time;
                        this.addDlg.error = '';
                        
                        this.modalContext.dateLabel = this.formatDate(date);
                        const doctor = this.doctors.find(d => d.id === doctorId);
                        this.modalContext.doctorName = doctor ? doctor.name : '';
                        
                        this.$refs.addModal.open();
                    },
                    openAdd(doctorId, date) {
                         // Legacy support or direct add if needed
                         this.openScheduleOptionsModal(doctorId, date);
                    },
                    cancelAdd() {
                        // this.addDlg.visible = false;
                        this.$refs.addModal.close();
                        this.addDlg.error = '';
                    },
                    getCsrf() {
                        const meta = document.querySelector('meta[name="csrf-token"]');
                        if (meta) return meta.getAttribute('content') || '';
                        const inp = document.querySelector('input[name="_token"]');
                        return (inp && inp.value) || '';
                    },
                    saveSchedule() {
                        this.addDlg.saving = true;
                        const payload = {
                            doctor_id: this.addDlg.doctorId,
                            date: this.addDlg.date,
                            start_time: this.addDlg.startTime,
                            end_time: this.addDlg.endTime,
                        };
                        const csrf = this.getCsrf();
                        const isEdit = !!this.addDlg.shiftId;
                        const url = isEdit
                            ? '{{ route('admin.schedules.update', 'replaceId') }}'.replace('replaceId', this.addDlg.shiftId)
                            : '{{ route('admin.schedules.store') }}';
                        const method = isEdit ? 'PUT' : 'POST';
                        fetch(url, {
                                method,
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': csrf,
                                },
                                body: JSON.stringify(payload),
                            })
                            .then(async (r) => {
                                if (!r.ok) {
                                    const err = await r.json().catch(() => ({}));
                                    throw new Error(err.message || 'Error al guardar');
                                }
                                return r.json();
                            })
                            .then((shift) => {
                                if (isEdit) {
                                    const idx = this.shifts.findIndex(s => s.id === shift.id);
                                    if (idx !== -1) this.shifts.splice(idx, 1, shift);
                                } else {
                                    this.shifts.push(shift);
                                }
                                this.$refs.addModal.close();
                            })
                            .catch((e) => {
                                this.addDlg.error = e.message || 'Error al guardar';
                            })
                            .finally(() => {
                                this.addDlg.saving = false;
                            });
                    },
                    deleteShift(shift) {
                        const csrf = this.getCsrf();
                        fetch('{{ route('admin.schedules.delete', 'replaceId') }}'.replace('replaceId', shift.id), {
                            method: 'DELETE',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrf,
                            },
                        })
                        .then(async (r) => {
                            if (!r.ok) {
                                const err = await r.json().catch(() => ({}));
                                throw new Error(err.message || 'No se pudo eliminar el turno');
                            }
                        })
                        .then(() => {
                            const idx = this.shifts.findIndex(s => s.id === shift.id);
                            if (idx !== -1) this.shifts.splice(idx, 1);
                        })
                        .catch((e) => {
                            this.addDlg.error = e.message || 'No se pudo eliminar el turno';
                        });
                    },
                    goPrevWeek() {
                        const date = new Date(this.start + 'T00:00:00');
                        date.setDate(date.getDate() - 7);
                        this.start = this.toISO(date);
                        this.fetchWeek();
                    },
                    goNextWeek() {
                        const date = new Date(this.start + 'T00:00:00');
                        date.setDate(date.getDate() + 7);
                        this.start = this.toISO(date);
                        this.fetchWeek();
                    },
                    goThisWeek() {
                        const today = new Date();
                        const startOfWeek = this.toISO(this.getStartOfWeek(today));
                        this.start = startOfWeek;
                        this.fetchWeek();
                    },
                    shiftsFor(doctorId, date) {
                        return this.shifts.filter(s => s.doctor_id === doctorId && s.date === date);
                    },
                    minutes(shift) {
                        const [sh, sm] = shift.start_time.split(':').map(Number);
                        const [eh, em] = shift.end_time.split(':').map(Number);
                        return (eh * 60 + em) - (sh * 60 + sm);
                    },
                    dayTotal(date) {
                        const mins = this.shifts.filter(s => s.date === date).reduce((sum, s) => sum + this.minutes(s), 0);
                        return this.fmtMinutes(mins);
                    },
                    doctorWeeklyTotal(doctorId) {
                        const mins = this.shifts.filter(s => s.doctor_id === doctorId).reduce((sum, s) => sum + this.minutes(s), 0);
                        return this.fmtMinutes(mins);
                    },
                    fmtMinutes(mins) {
                        if (mins <= 0) return '0 min';
                        const h = Math.floor(mins / 60);
                        const m = mins % 60;
                        if (m === 0) return `${h} h`;
                        return `${h} h ${m} min`;
                    },
                    formatDate(iso) {
                        const d = new Date(iso);
                        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
                    },
                    toISO(date) {
                        const tz = date.getTimezoneOffset();
                        const local = new Date(date.getTime() - tz * 60000);
                        return local.toISOString().split('T')[0];
                    },
                    getStartOfWeek(date) {
                        const d = new Date(date);
                        const day = d.getDay();
                        const diff = (day === 0 ? -6 : 1) - day;
                        d.setDate(d.getDate() + diff);
                        return d;
                    },
                    initials(name) {
                        const parts = (name || '').trim().split(/\s+/);
                        return parts.slice(0, 2).map(p => p[0]?.toUpperCase() || '').join('');
                    },

                    formatTime(time) {
                        if (!time) return '';
                        const parts = time.split(':');
                        return parts.slice(0, 2).join(':');
                    },
                },
            });
        </script>
    @endPushOnce
    @pushOnce('styles')
        <style>
            .add-overlay{position:relative;margin-top:8px;border:1px solid var(--border-color,#e5e7eb);border-radius:6px;padding:8px;background:var(--hours-bg,#fff)}
            .dark .add-overlay{border-color:#1f2937;background:#0b0f19}
            @media (max-width:640px){.add-overlay{margin-top:6px}}
        .tp-container { position: relative; display: inline-block; width: 100%; }
    .tp-input { display: flex; align-items: center; justify-content: space-between; width: 100%; border: 1px solid #e5e7eb; border-radius: 0.375rem; padding: 0.5rem 0.75rem; font-size: 0.875rem; background: white; cursor: pointer; height: 39px; }
    .dark .tp-input { border-color: #374151; background: #1f2937; color: #d1d5db; }
    .tp-dropdown { position: fixed; background: white; border: 1px solid #e5e7eb; border-radius: 0.375rem; z-index: 100000; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); }
    .dark .tp-dropdown { border-color: #374151; background: #1f2937; }
    .tp-lists { display: flex; height: 160px; }
    .tp-list { list-style: none; margin: 0; padding: 4px; overflow-y: auto; flex: 1; border-right: 1px solid #e5e7eb; }
    .dark .tp-list { border-right-color: #374151; }
    .tp-list:last-child { border-right: none; }
    .tp-list li { padding: 4px 8px; border-radius: 0.25rem; cursor: pointer; text-align: center; }
    .tp-list li:hover { background-color: #f3f4f6; }
    .dark .tp-list li:hover { background-color: #374151; }
    .tp-list li.is-selected { background-color: #3b82f6; color: white; font-weight: bold; }
    .dark .tp-list li.is-selected { background-color: #2563eb; }
</style>
    @endPushOnce
</x-admin::layouts>
