<script type="text/x-template" id="v-doctor-multiselect-template">
    <div class="ms-container" ref="root">
        <div class="ms-input" @click="toggleDropdown">
            <div class="ms-chips">
                <span class="ms-chip" v-for="sid in model.slice(0, 2)" :key="'chip-'+sid">
                    @{{ nameById(sid) }}
                    <i class="icon-cross-large ms-chip-x" @click.stop="remove(sid)"></i>
                </span>
                {{-- <span class="ms-chip" v-if="model.length > 2">
                    +@{{ model.length - 2 }}
                </span> --}}
            </div>
            <div class="ms-actions">
                <span class="ms-count">@{{ model.length > 2 ? model.length - 2 : 0 }}</span>
                <i :class="open ? 'icon-up-arrow' : 'icon-down-arrow'" class="text-xl"></i>
            </div>
        </div>
        <div v-if="open" class="ms-dropdown">
            <input type="text" v-model="q" class="ms-search" placeholder="Buscar" />
            <ul class="ms-list">
                <li v-for="d in filtered" :key="'li-'+d.id" class="ms-item" @click="toggle(String(d.id))">
                    <input type="checkbox" :checked="set.has(String(d.id))" />
                    <span>@{{ d && d.name ? d.name : '' }}</span>
                </li>
                <li v-if="!filtered.length" class="ms-empty">Sin resultados</li>
            </ul>
        </div>
    </div>
</script>

<script type="text/x-template" id="v-time-picker-template">
    <div class="tp-container" ref="root">
        <div class="tp-input" @click="toggle">
            <span>@{{ modelValue || '00:00' }}</span>
            <i class="icon-clock text-lg"></i>
        </div>
        <div v-if="isOpen" class="tp-dropdown" ref="dropdown">
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

<script type="text/x-template" id="v-doctor-day-calendar-template">
    <div class="dwc-container">
        <div class="dwc-controls">
            <div class="flex items-center gap-2">
                <button class="px-2 py-1 rounded border dark:border-gray-800" @click="prevPeriod">←</button>
                <button class="px-2 py-1 rounded border dark:border-gray-800" @click="goToday">Hoy</button>
                <button
                    type="button"
                    class="px-3 py-1 rounded border dark:border-gray-800 text-sm font-semibold dark:text-white"
                    @click="toggleDatePicker"
                >
                    @{{ dayLabel }}
                </button>
                <button class="px-2 py-1 rounded border dark:border-gray-800" @click="nextPeriod">→</button>
            </div>

            <div class="dwc-filters">
                <!-- Buscador de pacientes: nombre, teléfono o CI, con sus citas -->
                <div v-if="busqueda.abierto" class="ddc-buscador-overlay" @click="busqueda.abierto = false"></div>

                <div class="ddc-buscador">
                    <input
                        type="text"
                        class="ddc-buscador-input"
                        v-model="busqueda.q"
                        @input="buscarPacienteDiferido"
                        @focus="busqueda.abierto = busqueda.q.trim().length >= 3"
                        @keydown.esc="busqueda.abierto = false"
                        placeholder="Buscar paciente: nombre, teléfono o CI"
                    />

                    <div v-if="busqueda.abierto" class="ddc-buscador-panel">
                        <div v-if="busqueda.cargando" class="ddc-buscador-vacio">Buscando…</div>

                        <div v-else-if="! busqueda.resultados.length" class="ddc-buscador-vacio">
                            No hay pacientes para «@{{ busqueda.q }}»
                        </div>

                        <div v-else v-for="p in busqueda.resultados" :key="'bp-' + p.id" class="ddc-buscador-paciente">
                            <div class="ddc-buscador-nombre">@{{ p.name }}</div>

                            <div class="ddc-buscador-datos">
                                @{{ [p.telefono, p.ci ? 'CI ' + p.ci : null].filter(Boolean).join(' · ') }}
                            </div>

                            <div v-if="! p.citas.length" class="ddc-buscador-vacio">Sin citas</div>

                            <button
                                v-for="c in p.citas"
                                :key="'bc-' + c.id"
                                type="button"
                                class="ddc-buscador-cita"
                                :class="{ 'is-pasada': ! c.proxima }"
                                @click="irACita(c)"
                            >
                                <span>@{{ formatDate(new Date(c.start.replace(' ', 'T'))) }} · @{{ formatTime(c.start.replace(' ', 'T')) }}</span>
                                <span class="ddc-buscador-doctor">@{{ c.doctor_name || 'Sin doctor' }}</span>
                                <span v-if="c.proxima" class="ddc-buscador-tag">Próxima</span>
                            </button>
                        </div>
                    </div>
                </div>

                <span class="text-xs dark:text-gray-300">Filtrar doctores:</span>
                <button type="button" class="px-2 py-1 rounded border text-xs dark:border-gray-800" @click="selectAllDoctors">Todos</button>
                <button type="button" class="px-2 py-1 rounded border text-xs dark:border-gray-800" @click="clearDoctors">Ninguno</button>
                <v-doctor-multiselect style="max-width:350px;min-width:350px;max-height:100%" :items="doctors" v-model="selectedDoctorIds"></v-doctor-multiselect>
                
                <div class="flex items-center rounded border dark:border-gray-800 overflow-hidden bg-white dark:bg-gray-900">
                    <button type="button" class="px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-800" :class="viewType==='day'?'bg-gray-100 dark:bg-gray-800 font-semibold text-blue-600':''" @click="setView('day')">Día</button>
                    <button type="button" class="px-3 py-2 text-sm border-l dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800" :class="viewType==='week'?'bg-gray-100 dark:bg-gray-800 font-semibold text-blue-600':''" @click="setView('week')">Semana</button>
                    <button type="button" class="px-3 py-2 text-sm border-l dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800" :class="viewType==='month'?'bg-gray-100 dark:bg-gray-800 font-semibold text-blue-600':''" @click="setView('month')">Mes</button>
                </div>
            </div>
        </div>

        <div v-if="quickMenu.visible" class="ddc-quick-overlay" @click="closeQuickMenu"></div>

        <div
            v-if="quickMenu.visible"
            class="ddc-quick-menu"
            :style="{ top: quickMenu.top + 'px', left: quickMenu.left + 'px', width: quickMenu.width + 'px' }"
            @click.stop
        >
            <div class="ddc-quick-menu-head">
                <div class="ddc-quick-menu-time">@{{ quickMenu.timeText }}</div>
                <button type="button" class="ddc-quick-menu-close" @click="closeQuickMenu">×</button>
            </div>
            <div class="ddc-quick-menu-list">
                <button type="button" class="ddc-quick-menu-item" @click="openAppointmentModal">
                    <span class="icon-calendar text-xl"></span>
                    <span>Añadir cita</span>
                </button>
                
                <button type="button" class="ddc-quick-menu-item" @click="openGroupModal">
                    <span class="icon-calendar text-xl"></span>
                    <span>Añadir cita de grupo</span>
                </button>
                <!--
                <button type="button" class="ddc-quick-menu-item" @click="openScheduleOptionsModal">
                    <span class="icon-calendar text-xl"></span>
                    <span>Gestionar horario</span>
                </button>
                <a class="ddc-quick-menu-link" href="#">Ajustes de acciones rápidas</a>
                -->
            </div>
        </div>

        <div v-if="datePickerOpen" class="ddc-date-overlay" @click="closeDatePicker">
            <div class="ddc-date-panel" @click.stop>
                <div class="ddc-date-panel-header">
                    <button type="button" class="ddc-date-nav" @click="shiftPicker(-1)">‹</button>
                    <div class="ddc-date-months-header">
                        <div class="ddc-date-month-title">@{{ monthTitle(month1Date) }}</div>
                        <div class="ddc-date-month-title">@{{ monthTitle(month2Date) }}</div>
                    </div>
                    <button type="button" class="ddc-date-nav" @click="shiftPicker(1)">›</button>
                </div>

                <div class="ddc-date-months">
                    <div class="ddc-date-month">
                        <div class="ddc-date-weekdays">
                            <span v-for="w in weekdays" :key="'w1-'+w" class="ddc-date-weekday">@{{ w }}</span>
                        </div>
                        <div class="ddc-date-grid">
                            <button
                                v-for="cell in month1Cells"
                                :key="'m1-'+cell.key"
                                type="button"
                                class="ddc-date-day"
                                :class="{
                                    'is-out': !cell.inMonth,
                                    'is-selected': cell.iso === startISO,
                                    'is-today': cell.iso === todayISO()
                                }"
                                :disabled="!cell.inMonth"
                                @click="selectDate(cell.iso)"
                            >@{{ cell.day }}</button>
                        </div>
                    </div>

                    <div class="ddc-date-month">
                        <div class="ddc-date-weekdays">
                            <span v-for="w in weekdays" :key="'w2-'+w" class="ddc-date-weekday">@{{ w }}</span>
                        </div>
                        <div class="ddc-date-grid">
                            <button
                                v-for="cell in month2Cells"
                                :key="'m2-'+cell.key"
                                type="button"
                                class="ddc-date-day"
                                :class="{
                                    'is-out': !cell.inMonth,
                                    'is-selected': cell.iso === startISO,
                                    'is-today': cell.iso === todayISO()
                                }"
                                :disabled="!cell.inMonth"
                                @click="selectDate(cell.iso)"
                            >@{{ cell.day }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="viewType === 'month'" class="dwc-month-view">
            <div class="ddc-date-weekdays mb-0">
                <span v-for="w in weekdays" :key="'mw-'+w" class="ddc-date-weekday">@{{ w }}</span>
            </div>
            <div class="ddc-date-grid">
                <div v-for="cell in monthGridCells" :key="'mc-'+cell.key" class="ddc-date-day dwc-month-cell" 
                    :class="{ 'is-out': !cell.inMonth, 'is-today': cell.iso === todayISO() }"
                >
                    <div class="dwc-month-cell-header">
                        <span :class="{'font-bold text-blue-600': cell.iso === todayISO()}">@{{ cell.day }}</span>
                    </div>
                    <div class="dwc-month-cell-events custom-scrollbar">
                         <div v-for="ev in getEventsForDay(cell.iso)" :key="'mev-'+ev.id" 
                              class="dwc-month-event"
                              :title="ev.title || ev.type"
                              @click="editUrl(ev.id) ? window.location.href=editUrl(ev.id) : null"
                         >
                             <span class="font-semibold">@{{ formatTime(ev.start) }}</span> @{{ ev.title || ev.type }}
                         </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="viewType === 'week' && !isSingleDoctorMode" class="dwc-week-view">
            <div class="dwc-week-header">
                <div class="dwc-week-header-cell doctor-col">Doctores</div>
                <div v-for="day in days" :key="'wh-'+day.date" class="dwc-week-header-cell">
                    @{{ day.label }}
                </div>
            </div>
            <div class="dwc-week-body">
                <div v-for="col in columns" :key="'wrow-'+col.id" class="dwc-week-row">
                    <div class="dwc-week-doctor-cell">
                        <span class="font-semibold text-sm">@{{ col.name }}</span>
                        <span class="text-xs text-gray-500">@{{ totalCount(col.id) }} pedidos</span>
                    </div>
                    <div v-for="day in days" :key="'wcell-'+col.id+'-'+day.date" 
                         class="dwc-week-day-cell"
                         :class="{ 'is-today': day.date === todayISO() }"
                         @click="onWeekCellClick($event, day, col.id)"
                    >
                        <div v-for="ev in dayDoctorEvents(day.date, col.id)" :key="'wev-'+ev.id"
                             class="dwc-week-event"
                             :title="ev.title || ev.type"
                             @click.stop="editUrl(ev.id) ? window.location.href=editUrl(ev.id) : null"
                        >
                            <span class="font-bold">@{{ formatTime(ev.start) }}</span>
                            <span class="truncate">@{{ ev.title || ev.type }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="viewType === 'week' && isSingleDoctorMode" class="dwc-grid dwc-scroll-container" :style="{ gridTemplateColumns: gridCols }">
            <div class="dwc-time-col">
                <div
                    v-if="showNowLine"
                    class="dwc-now-line"
                    :style="{ top: nowTop + 'px' }"
                >
                    <div class="dwc-now-time">@{{ nowText }}</div>
                </div>

                <div class="dwc-hours">
                    <div v-for="h in 13" :key="'hr-'+h" class="dwc-hour-row" :style="{ height: hourHeight + 'px' }">@{{ pad2(h+6) }}:00</div>
                </div>
            </div>

            <div v-for="day in days" :key="'col-day-'+day.date" class="dwc-doctor-col">
                <div class="dwc-doctor-header justify-center">
                    <span class="font-bold">@{{ day.label }}</span>
                </div>

                <div class="dwc-days-stack" :style="{ height: dayHeight + 'px' }">
                    <div class="dwc-day-block" :style="{ height: dayHeight + 'px', borderBottom: 'none' }" @click="onDayClick($event, day, columns[0].id)">
                        <!-- Availability -->
                        <div v-for="av in getDoctorAvailability(day.date, columns[0].id)" :key="'av-sd-'+av.id" 
                                class="dwc-availability-block" 
                                :style="{ top: av.top + 'px', height: av.height + 'px' }">
                        </div>

                        <!-- Lines -->
                        <div v-for="idx in 13" :key="'line-sd-'+idx" class="dwc-hour-line" :style="{ top: ((idx - 1) * hourHeight) + 'px' }"></div>

                        <!-- Events -->
                        <div v-for="ev in dayDoctorEvents(day.date, columns[0].id)" :key="'ev-sd-'+ev.id" class="dwc-event" :style="{ top: ev.top + 'px', height: ev.height + 'px' }" @click.stop="editUrl(ev.id) ? window.location.href=editUrl(ev.id) : null">
                            <div class="dwc-event-content dwc-event-layout">
                                <div class="dwc-event-info">
                                    <div class="dwc-event-row-primary">
                                        <span class="dwc-event-patient" :title="ev.person_name">@{{ formatPatientName(ev.person_name) }}</span>
                                    </div>
                                    
                                    <div class="dwc-event-row-secondary">
                                        <span class="dwc-event-doctor" :title="ev.doctor_name">Dr. @{{ ev.doctor_name }}</span>
                                        <span class="dwc-event-separator">,</span>
                                        <span>@{{ formatTime(ev.start) }} - @{{ formatTime(ev.end) }}</span>
                                        <span class="dwc-event-separator">,</span>
                                        <span class="dwc-event-status-text">@{{ getStageName(ev.lead_pipeline_stage_id) }}</span>
                                    </div>

                                    <div v-if="ev.comment" class="dwc-event-reason" :title="ev.comment">
                                        @{{ ev.comment }}
                                    </div>
                                </div>

                                <div class="dwc-event-actions-wrapper">
                                    <div class="dwc-event-actions" @click.stop>
                                        <button type="button" class="dwc-action-btn" title="Ver Detalles" @click="openViewModal(ev)">
                                            <span class="icon-eye text-lg"></span>
                                        </button>
                                        <button type="button" class="dwc-action-btn" title="Editar Cita" @click="openEditModal(ev)">
                                            <span class="icon-edit text-lg"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="viewType === 'day'" class="dwc-grid dwc-scroll-container" :style="{ gridTemplateColumns: gridCols }">
            <div class="dwc-time-col">
                <div
                    v-if="showNowLine"
                    class="dwc-now-line"
                    :style="{ top: nowTop + 'px' }">
                    <div class="dwc-now-time">@{{ nowText }}</div>
                </div>

                <div class="dwc-hours">
                    <div v-for="h in 13" :key="'hr-'+h" class="dwc-hour-row" :style="{ height: hourHeight + 'px' }">@{{ pad2(h+6) }}:00</div>
                </div>
            </div>

            <div v-for="col in columns" :key="'col-'+col.id" class="dwc-doctor-col">
                    <div class="dwc-doctor-header">
                        <span>@{{ col && col.name ? col.name : '' }}</span>
                        <span class="text-xs dark:text-gray-300">@{{ totalCount(col.id) }}</span>
                    </div>

                    <div class="dwc-days-stack" :style="{ height: totalHeight + 'px' }">
                        <div v-for="(day, di) in days" :key="'day-'+di" class="dwc-day-block" :style="{ height: dayHeight + 'px' }" @click="onDayClick($event, day, col.id)">
                            <div v-for="av in getDoctorAvailability(day.date, col.id)" :key="'av-'+av.id" 
                                 class="dwc-availability-block" 
                                 :style="{ top: av.top + 'px', height: av.height + 'px' }">
                            </div>

                            <div v-for="idx in 13" :key="'line-'+di+'-'+idx" class="dwc-hour-line" :style="{ top: ((idx - 1) * hourHeight) + 'px' }"></div>

                            <div v-for="ev in dayDoctorEvents(day.date, col.id)" :key="'ev-'+ev.id" class="dwc-event" :style="{ top: ev.top + 'px', height: ev.height + 'px' }" @click.stop="editUrl(ev.id) ? window.location.href=editUrl(ev.id) : null">
                                <div class="dwc-event-content dwc-event-layout">
                                    <div class="dwc-event-info">
                                        <div class="dwc-event-row-primary">
                                            <span class="dwc-event-patient" :title="ev.person_name">@{{ formatPatientName(ev.person_name) }}</span>
                                        </div>
                                        
                                        <div class="dwc-event-row-secondary">
                                            <span class="dwc-event-doctor" :title="ev.doctor_name">Dr. @{{ ev.doctor_name }}</span>
                                            <span class="dwc-event-separator">,</span>
                                            <span>@{{ formatTime(ev.start) }} - @{{ formatTime(ev.end) }}</span>
                                            <span class="dwc-event-separator">,</span>
                                            <span class="dwc-event-status-text">@{{ getStageName(ev.lead_pipeline_stage_id) }}</span>
                                        </div>

                                        <div v-if="ev.comment" class="dwc-event-reason" :title="ev.comment">
                                            @{{ ev.comment }}
                                        </div>
                                    </div>

                                    <div class="dwc-event-actions-wrapper">
                                        <div class="dwc-event-actions" @click.stop>
                                            <button type="button" class="dwc-action-btn" title="Ver Detalles" @click="openViewModal(ev)">
                                                <span class="icon-eye text-lg"></span>
                                            </button>
                                            <button type="button" class="dwc-action-btn" title="Editar Cita" @click="openEditModal(ev)">
                                                <span class="icon-edit text-lg"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
        </div>

        <x-admin::modal ref="appointmentModal">
            <x-slot:header>
                <div class="text-lg font-semibold dark:text-white">
                    Añadir cita
                </div>

                <div class="text-sm text-gray-500 dark:text-gray-300">
                    @{{ modalContext.dayLabel }} · @{{ modalContext.timeText }}
                </div>
            </x-slot>

            <x-slot:content>
                <div class="flex flex-col gap-3">
                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        @{{ modalContext.dayLabel }} · @{{ modalContext.timeText }}
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2">
                            <div class="flex flex-col gap-1">
                                <div class="text-xs text-gray-600 dark:text-gray-300">Paciente</div>
                                <x-admin::lookup
                                    ::src="personSearchUrl"
                                    ::params="{ limit: 200 }"
                                    name="appointment_person"
                                    placeholder="Paciente"
                                    :can-add-new="true"
                                    @on-selected="onSelectPatient"
                                    ::value="{ id: appointmentForm.person.id, name: appointmentForm.person.name }"
                                />
                            </div>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Doctor</div>
                            <select
                                class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                v-model="appointmentForm.doctor_id"
                            >
                                <option :value="null" disabled>Doctor</option>
                                <option v-for="d in doctors" :key="'doc-opt-'+d.id" :value="d.id">@{{ d && d.name ? d.name : '' }}</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Producto</div>
                            <x-admin::lookup
                                class="col-span-1"
                                ::src="productSearchUrl"
                                ::params="{ limit: 200 }"
                                name="appointment_product"
                                placeholder="Producto"
                                @on-selected="onSelectService"
                                ::value="{ id: appointmentForm.product_id, name: appointmentForm.product_name }"
                            />
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Duración</div>
                            <select
                                class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                v-model.number="appointmentForm.duration"
                                @change="syncAppointmentEndTime"
                            >
                                <option :value="15">15 min</option>
                                <option :value="30">30 min</option>
                                <option :value="45">45 min</option>
                                <option :value="60">60 min</option>
                                <option :value="90">90 min</option>
                                <option :value="120">120 min</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Hora Inicio</div>
                            <v-time-picker
                                v-model="appointmentForm.startTime"
                                @update:modelValue="syncAppointmentEndTime"
                            ></v-time-picker>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Hora Fin</div>
                            <v-time-picker
                                v-model="appointmentForm.endTime"
                                :disabled="true"
                            ></v-time-picker>
                        </div>

                        <div class="col-span-2">
                            <div class="flex flex-col gap-1">
                                <div class="text-xs text-gray-600 dark:text-gray-300">Estado</div>
                                <select
                                    class="col-span-2 rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                    v-model="appointmentForm.lead_pipeline_stage_id"
                                >
                                    <option :value="null" disabled>Estado</option>
                                    <option v-for="stage in stages" :key="'st-modal-'+stage.id" :value="stage.id">@{{ stage.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-span-2">
                            <div class="flex flex-col gap-1">
                                <div class="text-xs text-gray-600 dark:text-gray-300">Motivo de consulta</div>
                                <textarea
                                    rows="3"
                                    class="col-span-2 rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                    v-model="appointmentForm.reason"
                                    placeholder="Motivo de consulta"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="text-sm text-red-600" v-if="modalError">@{{ modalError }}</div>
                </div>
            </x-slot>

            <x-slot:footer>
                <div class="flex items-center gap-2 justify-end">
                    <button type="button" class="secondary-button" @click="$refs.appointmentModal.close()">Cancelar</button>
                    <button type="button" class="primary-button" @click="saveAppointment" :disabled="modalSaving">
                        <span v-if="!modalSaving">Guardar</span>
                        <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando...</span>
                    </button>
                </div>
            </x-slot>
        </x-admin::modal>

        <!-- Schedule Options Modal (Small) -->
        <x-admin::modal ref="scheduleOptionsModal">
            <x-slot:header>
                <div class="text-lg font-semibold dark:text-white">
                    Gestionar Horario
                </div>
            </x-slot>
            <x-slot:content>
                <div class="flex flex-col gap-2">
                    <button type="button" class="w-full text-left px-4 py-3 rounded border hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center gap-3" @click="openUnavailableModal">
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
                    <button type="button" class="w-full text-left px-4 py-3 rounded border hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center gap-3" @click="openTimeOffModal">
                        <span class="icon-calendar text-xl text-orange-600"></span>
                        <div>
                            <div class="font-semibold text-sm">Añadir días libres</div>
                            <div class="text-xs text-gray-500">Registrar vacaciones o ausencias</div>
                        </div>
                    </button>
                </div>
            </x-slot>
        </x-admin::modal>

        <!-- Recurring Shifts Modal -->
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
                    
                    <div class="text-sm text-red-600" v-if="modalError">@{{ modalError }}</div>
                </div>
            </x-slot>
            <x-slot:footer>
                <div class="flex items-center gap-2 justify-end">
                    <button type="button" class="secondary-button" @click="$refs.recurringModal.close()">Cancelar</button>
                    <button type="button" class="primary-button" @click="saveRecurring" :disabled="modalSaving">
                        <span v-if="!modalSaving">Generar Turnos</span>
                        <span v-else class="flex items-center gap-2"><x-admin::spinner /> Procesando...</span>
                    </button>
                </div>
            </x-slot>
        </x-admin::modal>

        <!-- Time Off Modal -->
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
                            <select class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="timeOffForm.doctor_id">
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
                    
                    <div class="text-sm text-red-600" v-if="modalError">@{{ modalError }}</div>
                </div>
            </x-slot>
            <x-slot:footer>
                <div class="flex items-center gap-2 justify-end">
                    <button type="button" class="secondary-button" @click="$refs.timeOffModal.close()">Cancelar</button>
                    <button type="button" class="primary-button bg-black hover:bg-gray-800 text-white" @click="saveTimeOff" :disabled="modalSaving">
                        <span v-if="!modalSaving">Guardar</span>
                        <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando...</span>
                    </button>
                </div>
            </x-slot>
        </x-admin::modal>

        <x-admin::modal ref="viewModal">
            <x-slot:header>
                <div class="text-lg font-semibold dark:text-white">
                    Detalles de la Cita
                </div>
            </x-slot>

            <x-slot:content>
                <div class="flex flex-col gap-4" v-if="viewAppointment.data">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex flex-col">
                            <span class="text-xs text-gray-500 font-medium uppercase">Paciente</span>
                            <span class="text-sm font-semibold dark:text-white">@{{ viewAppointment.data.person_name || '-' }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-gray-500 font-medium uppercase">Doctor</span>
                            <span class="text-sm font-semibold dark:text-white">@{{ viewAppointment.data.doctor_name || '-' }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-gray-500 font-medium uppercase">Fecha</span>
                            <span class="text-sm dark:text-gray-300">@{{ formatDate(new Date(viewAppointment.data.start)) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-gray-500 font-medium uppercase">Horario</span>
                            <span class="text-sm dark:text-gray-300">
                                @{{ formatTime(viewAppointment.data.start) }} - @{{ formatTime(viewAppointment.data.end) }}
                                <span class="text-gray-400">(@{{ getDuration(viewAppointment.data.start, viewAppointment.data.end) }})</span>
                            </span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-gray-500 font-medium uppercase">Producto</span>
                            <span class="text-sm font-semibold dark:text-white">
                                @{{ viewAppointment.data.product_name || (viewAppointment.data.product ? viewAppointment.data.product.name : '-') }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-col" v-if="viewAppointment.data.comment">
                        <span class="text-xs text-gray-500 font-medium uppercase mb-1">Motivo de Consulta</span>
                        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">
                            @{{ viewAppointment.data.comment }}
                        </div>
                    </div>

                    <div class="flex flex-col">
                        <span class="text-xs text-gray-500 font-medium uppercase mb-1">Estado</span>
                        <div class="inline-flex">
                            <span class="px-2 py-1 rounded text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                @{{ getStageName(viewAppointment.data.lead_pipeline_stage_id) || 'Sin Estado' }}
                            </span>
                        </div>
                    </div>
                </div>
            </x-slot>

            <x-slot:footer>
                <div class="flex items-center gap-2 justify-end">
                    <button type="button" class="secondary-button" @click="$refs.viewModal.close()">Cerrar</button>
                </div>
            </x-slot>
        </x-admin::modal>

        <x-admin::modal ref="groupModal">
            <x-slot:header>
                <div class="text-lg font-semibold dark:text-white">
                    Añadir cita de grupo
                </div>

                <div class="text-sm text-gray-500 dark:text-gray-300">
                    @{{ modalContext.dayLabel }} · @{{ modalContext.timeText }}
                </div>
            </x-slot>

            <x-slot:content>
                <div class="flex flex-col gap-3">
                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        @{{ modalContext.dayLabel }} · @{{ modalContext.timeText }}
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Título</div>
                            <input
                                type="text"
                                class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                v-model="groupForm.title"
                                placeholder="Título"
                            />
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Tipo</div>
                            <select
                                class="rounded border px-2 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                                v-model="groupForm.type"
                            >
                                <option value="meeting">Consulta</option>
                                <option value="call">Llamada</option>
                                <option value="lunch">Almuerzo</option>
                            </select>
                        </div>
                        
                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Hora Inicio</div>
                            <v-time-picker v-model="groupForm.startTime"></v-time-picker>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Hora Fin</div>
                            <v-time-picker v-model="groupForm.endTime"></v-time-picker>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <div class="text-xs text-gray-600 dark:text-gray-300">Doctores</div>
                        <v-doctor-multiselect :items="doctors" v-model="groupForm.doctorIds"></v-doctor-multiselect>
                    </div>

                    <div class="text-sm text-red-600" v-if="modalError">@{{ modalError }}</div>
                </div>
            </x-slot>

            <x-slot:footer>
                <div class="flex items-center gap-2 justify-end">
                    <button type="button" class="secondary-button" @click="$refs.groupModal.close()">Cancelar</button>
                    <button type="button" class="primary-button" @click="saveGroupAppointment" :disabled="modalSaving">
                        <span v-if="!modalSaving">Guardar</span>
                        <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando...</span>
                    </button>
                </div>
            </x-slot>
        </x-admin::modal>

        <x-admin::modal ref="unavailableModal">
            <x-slot:header>
                <div class="text-lg font-semibold dark:text-white">
                    Añadir horario no disponible
                </div>

                <div class="text-sm text-gray-500 dark:text-gray-300">
                    @{{ modalContext.dayLabel }} · @{{ modalContext.doctorLabel }}
                </div>
            </x-slot>

            <x-slot:content>
                <div class="flex flex-col gap-3">
                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        @{{ modalContext.dayLabel }} · @{{ modalContext.doctorLabel }}
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Hora Inicio</div>
                            <v-time-picker v-model="unavailableForm.startTime"></v-time-picker>
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Hora Fin</div>
                            <v-time-picker v-model="unavailableForm.endTime"></v-time-picker>
                        </div>
                    </div>

                    <div class="text-sm text-red-600" v-if="modalError">@{{ modalError }}</div>
                </div>
            </x-slot>

            <x-slot:footer>
                <div class="flex items-center gap-2 justify-end">
                    <button type="button" class="secondary-button" @click="$refs.unavailableModal.close()">Cancelar</button>
                    <button type="button" class="primary-button" @click="saveUnavailable" :disabled="modalSaving">
                        <span v-if="!modalSaving">Guardar</span>
                        <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando...</span>
                    </button>
                </div>
            </x-slot>
        </x-admin::modal>
    </div>
</script>

<script type="module">
    app.component('v-doctor-multiselect', {
        template: '#v-doctor-multiselect-template',
        props: {
            items: {
                type: Array,
                default: () => []
            },
            modelValue: {
                type: Array,
                default: () => []
            },
        },
        emits: ['update:modelValue'],
        data() {
            return {
                open: false,
                q: ''
            };
        },
        computed: {
            model() {
                return this.modelValue;
            },
            set() {
                return new Set(this.model);
            },
            filtered() {
                const q = this.q.trim().toLowerCase();
                return q ?
                    this.items.filter(d => d && String(d.name || '').toLowerCase().includes(q)) :
                    this.items.filter(d => d);
            },
        },
        methods: {
            toggleDropdown() {
                this.open = !this.open;
                if (this.open) {
                    this.$nextTick(() => {
                        const inputRect = this.$refs.root.getBoundingClientRect();
                        const dropdown = this.$refs.root.querySelector('.ms-dropdown');
                        if (dropdown) {
                            dropdown.style.left = inputRect.left + 'px';
                            dropdown.style.top = inputRect.bottom + 4 + 'px';
                            dropdown.style.width = inputRect.width + 'px';
                        }
                    });
                }
            },
            nameById(id) {
                const d = this.items.find(x => x && String(x.id) === String(id));
                return d ? d.name : '';
            },
            toggle(id) {
                const next = new Set(this.model);
                if (next.has(id)) next.delete(id);
                else next.add(id);
                this.$emit('update:modelValue', Array.from(next));
            },
            remove(id) {
                const next = this.model.filter(x => String(x) !== String(id));
                this.$emit('update:modelValue', next);
            },
            onClickOutside(e) {
                const root = this.$refs.root;
                if (root && !root.contains(e.target)) this.open = false;
            },
        },
        mounted() {
            window.addEventListener('click', this.onClickOutside);
        },
        beforeUnmount() {
            window.removeEventListener('click', this.onClickOutside);
        },
    });

    app.component('v-time-picker', {
        template: '#v-time-picker-template',
        props: ['modelValue'],
        emits: ['update:modelValue'],
        data() {
            return {
                isOpen: false,
                dropdownEl: null,
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
            isOpen(isOpen) {
                if (isOpen) {
                    this.$nextTick(() => {
                        const dropdown = this.$refs.dropdown;
                        if (!dropdown) return;

                        this.dropdownEl = dropdown;
                        // En lugar de mover el elemento al body, lo dejamos donde está
                        // para evitar problemas con Vue al desmontar/actualizar
                        
                        const inputRect = this.$refs.root.getBoundingClientRect();
                        this.dropdownEl.style.position = 'absolute';
                        this.dropdownEl.style.top = `100%`;
                        this.dropdownEl.style.left = `0`;
                        this.dropdownEl.style.width = `100%`;
                        this.dropdownEl.style.zIndex = `9999`;

                        this.scrollToSelected();
                    });
                } else {
                    this.dropdownEl = null;
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
            },
            selectMinute(m) {
                const h = this.currentHour;
                this.$emit('update:modelValue', `${this.pad2(h)}:${this.pad2(m)}`);
                this.isOpen = false;
            },
            scrollToSelected() {
                if (!this.dropdownEl) return;
                const hoursEl = this.dropdownEl.querySelector('.tp-lists ul:first-of-type');
                const minutesEl = this.dropdownEl.querySelector('.tp-lists ul:last-of-type');
                if (hoursEl) {
                    const selected = hoursEl.querySelector('.is-selected');
                    if (selected) {
                        hoursEl.scrollTop = selected.offsetTop - (hoursEl.offsetHeight / 2) + (selected
                            .offsetHeight / 2);
                    }
                }
                if (minutesEl) {
                    const selected = minutesEl.querySelector('.is-selected');
                    if (selected) {
                        minutesEl.scrollTop = selected.offsetTop - (minutesEl.offsetHeight / 2) + (selected
                            .offsetHeight / 2);
                    }
                }
            },
            onClickOutside(e) {
                if (this.$refs.root && !this.$refs.root.contains(e.target) &&
                    this.dropdownEl && !this.dropdownEl.contains(e.target)) {
                    this.isOpen = false;
                }
            }
        },
        mounted() {
            document.addEventListener('click', this.onClickOutside, true);
        },
        beforeUnmount() {
            document.removeEventListener('click', this.onClickOutside, true);
            if (this.dropdownEl && this.dropdownEl.parentNode === document.body) {
                document.body.removeChild(this.dropdownEl);
            }
        }
    });

    app.component('v-doctor-day-calendar', {
        template: '#v-doctor-day-calendar-template',
        data() {
            const today = new Date();
            const start = new Date(today);

            return {
                isLoading: false,
                isSaving: false,
                addError: '',
                hourHeight: 64,
                startISO: this.toISO(start),
                days: [],
                doctors: [],
                appointments: [],
                availability: [],
                stages: [],
                selectedDoctorIds: [],
                doctorFilterInitialized: false,
                nowMinutes: 0,
                nowText: '',
                nowTimer: null,
                datePickerOpen: false,
                datePickerMonthISO: '',
                busqueda: {
                    q: '',
                    resultados: [],
                    abierto: false,
                    cargando: false,
                    timer: null,
                },
                weekdays: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
                quickMenu: {
                    visible: false,
                    top: 0,
                    left: 0,
                    width: 320,
                    height: 190,
                    doctorId: null,
                    doctorLabel: '',
                    dayISO: '',
                    dayLabel: '',
                    startTime: '09:00',
                    endTime: '10:00',
                    timeText: '09:00',
                },
                modalContext: {
                    doctorId: null,
                    doctorLabel: '',
                    dayISO: '',
                    dayLabel: '',
                    timeText: '',
                },
                appointmentForm: {
                    id: null,
                    person: {
                        id: '',
                        name: '',
                    },
                    doctor_id: null,
                    product_id: null,
                    product_name: '',
                    duration: 60,
                    startTime: '09:00',
                    endTime: '10:00',
                    reason: '',
                    lead_pipeline_stage_id: null,
                },
                viewAppointment: {
                    visible: false,
                    data: null
                },
                groupForm: {
                    title: '',
                    type: 'meeting',
                    startTime: '09:00',
                    endTime: '10:00',
                    doctorIds: [],
                },
                unavailableForm: {
                    startTime: '09:00',
                    endTime: '10:00',
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
                modalSaving: false,
                modalError: '',
                endpoint: "{{ route('admin.activities.get') }}",
                storeUrl: "{{ route('admin.activities.store') }}",
                appointmentStoreUrl: "{{ route('admin.activities.appointments.store') }}",
                personSearchUrl: "{{ route('admin.contacts.persons.search') }}",
                buscarPacienteUrl: "{{ route('admin.activities.buscar-paciente') }}",
                productSearchUrl: "{{ route('admin.products.search') }}",
                editUrlTemplate: "{{ route('admin.activities.edit', 'replaceId') }}",
                deleteUrlTemplate: "{{ route('admin.activities.delete', 'replaceId') }}",
                scheduleStoreUrl: "{{ route('admin.schedules.store') }}",
                viewType: 'day',
            };
        },
        computed: {
            minuteHeight() {
                return this.hourHeight / 60;
            },
            dayHeight() {
                return 13 * this.hourHeight;
            },
            totalHeight() {
                return this.days.length * this.dayHeight;
            },
            dayLabel() {
                if (this.viewType === 'week' && this.days.length) {
                    const s = new Date(this.days[0].date);
                    const e = new Date(this.days[this.days.length - 1].date);
                    return `${this.formatDate(s)} - ${this.formatDate(e)}`;
                }
                if (this.viewType === 'month') {
                    const d = this.parseISODate(this.startISO);
                    const months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto',
                        'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
                    ];
                    return `${months[d.getMonth()]} ${d.getFullYear()}`;
                }
                const d = this.parseISODate(this.startISO);
                const names = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                const dd = String(d.getDate()).padStart(2, '0');
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const yyyy = d.getFullYear();
                return `${names[d.getDay()]} ${dd}/${mm}/${yyyy}`;
            },
            columns() {
                const ids = new Set(this.selectedDoctorIds.map(id => Number(id)));
                return this.doctors.filter(d => d && d.id && ids.has(Number(d.id)));
            },
            gridCols() {
                if (this.viewType === 'month') return 'repeat(7, 1fr)';

                if (this.isSingleDoctorMode && this.viewType === 'week') {
                    return `80px repeat(${this.days.length}, minmax(200px, 1fr))`;
                }

                const count = this.columns.length;
                return count ? `80px repeat(${count}, minmax(220px, 1fr))` : '80px';
            },
            isSingleDoctorMode() {
                return this.columns.length === 1;
            },
            monthGridCells() {
                if (this.viewType !== 'month') return [];
                const d = this.parseISODate(this.startISO);
                // Ensure we are working with the month of startISO
                return this.buildMonthCells(new Date(d.getFullYear(), d.getMonth(), 1));
            },
            showNowLine() {
                return this.startISO === this.todayISO() && this.nowMinutes >= (7 * 60) && this.nowMinutes < (
                    23 * 60);
            },
            nowTop() {
                return (this.nowMinutes - (7 * 60)) * this.minuteHeight + 30;
            },
            month1Date() {
                const base = this.datePickerMonthISO ? this.parseISODate(this.datePickerMonthISO) : this
                    .parseISODate(this.startISO);
                return new Date(base.getFullYear(), base.getMonth(), 1);
            },
            month2Date() {
                return new Date(this.month1Date.getFullYear(), this.month1Date.getMonth() + 1, 1);
            },
            month1Cells() {
                return this.buildMonthCells(this.month1Date);
            },
            month2Cells() {
                return this.buildMonthCells(this.month2Date);
            },
        },
        mounted() {
            this.fetch();
            this.updateNow();
            this.nowTimer = window.setInterval(this.updateNow, 30000);
            window.addEventListener('keydown', this.onKeyDown);
        },
        beforeUnmount() {
            if (this.nowTimer) {
                clearInterval(this.nowTimer);
                this.nowTimer = null;
            }
            window.removeEventListener('keydown', this.onKeyDown);
        },
        methods: {
            pad2(n) {
                return String(n).padStart(2, '0');
            },
            parseISODate(iso) {
                if (!iso || typeof iso !== 'string') {
                    return new Date();
                }

                const parts = iso.split('-').map(Number);

                if (parts.length !== 3 || parts.some(Number.isNaN)) {
                    return new Date();
                }

                const [year, month, day] = parts;

                return new Date(year, month - 1, day);
            },
            monthTitle(d) {
                const months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                    'septiembre', 'octubre', 'noviembre', 'diciembre'
                ];
                const name = months[d.getMonth()];
                const cap = name.charAt(0).toUpperCase() + name.slice(1);
                return `${cap} ${d.getFullYear()}`;
            },
            buildMonthCells(monthStart) {
                const y = monthStart.getFullYear();
                const m = monthStart.getMonth();
                const first = new Date(y, m, 1);
                const last = new Date(y, m + 1, 0);
                const daysInMonth = last.getDate();
                const offset = first.getDay();
                const cells = [];
                for (let i = 0; i < offset; i++) {
                    cells.push({
                        key: `o-${y}-${m}-${i}`,
                        inMonth: false,
                        day: '',
                        iso: ''
                    });
                }
                for (let day = 1; day <= daysInMonth; day++) {
                    const iso = this.toISO(new Date(y, m, day));
                    cells.push({
                        key: `d-${iso}`,
                        inMonth: true,
                        day,
                        iso
                    });
                }
                while (cells.length % 7 !== 0) {
                    const i = cells.length;
                    cells.push({
                        key: `t-${y}-${m}-${i}`,
                        inMonth: false,
                        day: '',
                        iso: ''
                    });
                }
                return cells;
            },
            todayISO() {
                return this.toISO(new Date());
            },
            updateNow() {
                const now = new Date();
                this.nowMinutes = now.getHours() * 60 + now.getMinutes();
                this.nowText = `${this.pad2(now.getHours())}:${this.pad2(now.getMinutes())}`;
            },
            timeToMinutes(time) {
                if (!time) return 0;
                const [h, m] = String(time).split(':').map(Number);
                if (Number.isNaN(h) || Number.isNaN(m)) return 0;
                return (h * 60) + m;
            },
            minutesToTime(mins) {
                const safe = Math.max(0, Math.min(24 * 60 - 1, mins));
                const h = Math.floor(safe / 60);
                const m = safe % 60;
                return `${this.pad2(h)}:${this.pad2(m)}`;
            },
            syncAppointmentEndTime() {
                const startMin = this.timeToMinutes(this.appointmentForm.startTime);
                const duration = Number(this.appointmentForm.duration) || 0;
                const endMin = startMin + duration;
                this.appointmentForm.endTime = this.minutesToTime(endMin);
            },
            syncAppointmentDurationFromTimes() {
                const startMin = this.timeToMinutes(this.appointmentForm.startTime);
                const endMin = this.timeToMinutes(this.appointmentForm.endTime);
                const diff = endMin - startMin;
                if (diff > 0) {
                    this.appointmentForm.duration = diff;
                }
            },
            onSelectPatient(result) {
                let phone = '';
                if (result?.contact_numbers && Array.isArray(result.contact_numbers) && result.contact_numbers
                    .length > 0) {
                    phone = result.contact_numbers[0].value || '';
                }

                this.appointmentForm.person = {
                    id: result?.id || null,
                    name: result?.name || '',
                    phone: phone,
                };
            },
            onSelectService(result) {
                this.appointmentForm.product_id = result?.id || null;
                this.appointmentForm.product_name = result?.name || '';
            },
            closeDatePicker() {
                this.datePickerOpen = false;
            },
            shiftPicker(deltaMonths) {
                const base = this.datePickerMonthISO ? this.parseISODate(this.datePickerMonthISO) : this
                    .parseISODate(this.startISO);
                const d = new Date(base.getFullYear(), base.getMonth() + deltaMonths, 1);
                this.datePickerMonthISO = this.toISO(d);
            },
            selectDate(iso) {
                if (!iso) return;
                this.startISO = iso;
                this.closeDatePicker();
                this.fetch();
            },
            onKeyDown(e) {
                if (e.key === 'Escape' && this.datePickerOpen) {
                    this.closeDatePicker();
                }
                if (e.key === 'Escape' && this.quickMenu.visible) {
                    this.closeQuickMenu();
                }
            },
            toISO(d) {
                const yyyy = d.getFullYear();
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                return `${yyyy}-${mm}-${dd}`;
            },
            formatDate(d) {
                const wd = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][d.getDay()];
                const dd = String(d.getDate()).padStart(2, '0');
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const yyyy = d.getFullYear();
                return `${wd} ${dd}/${mm}/${yyyy}`;
            },
            formatTime(dt) {
                const t = new Date(dt);
                return t.toLocaleTimeString(undefined, {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                });
            },
            setView(type) {
                this.viewType = type;
                this.fetch();
            },
            prevPeriod() {
                const d = this.parseISODate(this.startISO);
                if (this.viewType === 'week') d.setDate(d.getDate() - 7);
                else if (this.viewType === 'month') d.setMonth(d.getMonth() - 1);
                else d.setDate(d.getDate() - 1);
                this.startISO = this.toISO(d);
                this.fetch();
            },
            nextPeriod() {
                const d = this.parseISODate(this.startISO);
                if (this.viewType === 'week') d.setDate(d.getDate() + 7);
                else if (this.viewType === 'month') d.setMonth(d.getMonth() + 1);
                else d.setDate(d.getDate() + 1);
                this.startISO = this.toISO(d);
                this.fetch();
            },
            goToday() {
                const today = new Date();
                this.startISO = this.toISO(today);
                this.fetch();
            },
            fetch() {
                this.isLoading = true;

                // Devuelve la promesa: irACita() abre la cita cuando termina de cargar el día.
                return this.$axios.get(this.endpoint, {
                        params: {
                            view_type: 'calendar',
                            calendar_mode: 'doctor',
                            calendar_view: this.viewType,
                            start: this.startISO
                        }
                    })
                    .then(r => {
                        this.days = r.data.days;
                        this.doctors = (r.data.doctors || []).filter(d => d && d.id);
                        this.appointments = r.data.appointments;
                        this.availability = r.data.availability || [];
                        this.stages = r.data.stages || [];

                        if (!this.doctorFilterInitialized) {
                            this.selectedDoctorIds = this.doctors.map(d => String(d.id));
                            this.doctorFilterInitialized = true;
                        }

                        this.isLoading = false;
                    })
                    .catch(() => this.isLoading = false);
            },
            buscarPacienteDiferido() {
                clearTimeout(this.busqueda.timer);

                const q = this.busqueda.q.trim();

                if (q.length < 3) {
                    this.busqueda.resultados = [];
                    this.busqueda.abierto = false;

                    return;
                }

                this.busqueda.timer = setTimeout(() => this.buscarPaciente(q), 300);
            },
            buscarPaciente(q) {
                this.busqueda.cargando = true;
                this.busqueda.abierto = true;

                this.$axios.get(this.buscarPacienteUrl, { params: { q } })
                    .then(r => {
                        // Si mientras tanto se siguió escribiendo, esta respuesta ya no vale.
                        if (q === this.busqueda.q.trim()) {
                            this.busqueda.resultados = r.data.pacientes || [];
                        }
                    })
                    .catch(() => this.busqueda.resultados = [])
                    .finally(() => this.busqueda.cargando = false);
            },
            irACita(cita) {
                this.busqueda.abierto = false;

                // Que el doctor de la cita esté visible aunque se lo haya filtrado.
                if (cita.doctor_id && ! this.selectedDoctorIds.includes(String(cita.doctor_id))) {
                    this.selectedDoctorIds = [...this.selectedDoctorIds, String(cita.doctor_id)];
                }

                this.startISO = cita.start.substring(0, 10);
                this.viewType = 'day';

                this.fetch().then(() => {
                    const ev = this.appointments.find(a => String(a.id) === String(cita.id));

                    if (ev) {
                        this.openViewModal(ev);
                    }
                });
            },
            selectAllDoctors() {
                this.selectedDoctorIds = this.doctors.map(d => String(d.id));
            },
            clearDoctors() {
                this.selectedDoctorIds = [];
            },
            getEventsForDay(isoDate) {
                if (!isoDate || !this.selectedDoctorIds.length) return [];
                const ids = new Set(this.selectedDoctorIds.map(id => String(id)));
                return this.appointments.filter(a =>
                    a.start.startsWith(isoDate) &&
                    ids.has(String(a.doctor_id))
                );
            },
            getDoctorAvailability(dateStr, doctorId) {
                return this.availability
                    .filter(s => s.date === dateStr && String(s.doctor_id) === String(doctorId))
                    .map(s => {
                        const startMin = this.timeToMinutes(s.start_time);
                        const endMin = this.timeToMinutes(s.end_time);
                        const top = (startMin - (7 * 60)) * this.minuteHeight;
                        const height = (endMin - startMin) * this.minuteHeight;
                        return {
                            ...s,
                            top,
                            height
                        };
                    });
            },
            dayDoctorEvents(dateStr, doctorId) {
                return this.appointments
                    .filter(a => a.start.split(' ')[0] === dateStr && String(a.doctor_id) === String(doctorId))
                    .map(a => {
                        const dtStart = new Date(a.start);
                        const dtEnd = new Date(a.end);
                        const startMin = dtStart.getHours() * 60 + dtStart.getMinutes();
                        const endMin = dtEnd.getHours() * 60 + dtEnd.getMinutes();
                        const top = (startMin - (7 * 60)) * this.minuteHeight;
                        const height = Math.max((endMin - startMin) * this.minuteHeight, 8);
                        return {
                            ...a,
                            top,
                            height
                        };
                    });
            },
            totalCount(doctorId) {
                return this.appointments.filter(a => String(a.doctor_id) === String(doctorId)).length;
            },
            onDayClick(e, day, doctorId) {
                const container = e.currentTarget;
                const rect = container.getBoundingClientRect();
                const yLocal = e.clientY - rect.top;

                const minutes = Math.max(0, Math.min(14 * 60 - 1, Math.round(yLocal / this.minuteHeight))) + (
                    7 * 60);

                // Check availability
                const availableSlots = this.getDoctorAvailability(day.date, doctorId);
                const isAvailable = availableSlots.some(slot => {
                    const start = this.timeToMinutes(slot.start_time);
                    const end = this.timeToMinutes(slot.end_time);
                    return minutes >= start && minutes < end;
                });

                if (!isAvailable) return;

                const h = Math.floor(minutes / 60);
                const m = minutes % 60;

                const endMins = Math.min(23 * 60 + 59, minutes + 60);
                const eh = Math.floor(endMins / 60);
                const em = endMins % 60;

                const xView = e.clientX;
                const yView = e.clientY;
                const desiredLeft = xView + 8;
                const maxLeft = window.innerWidth - this.quickMenu.width - 8;
                const left = desiredLeft <= maxLeft ? desiredLeft : Math.max(8, xView - this.quickMenu.width -
                    8);

                const desiredTop = yView - this.quickMenu.height - 8;
                const minTop = 8;
                const maxTop = window.innerHeight - this.quickMenu.height - 8;
                const top = desiredTop >= minTop ? desiredTop : Math.min(maxTop, yView + 8);

                this.quickMenu.visible = true;
                this.quickMenu.left = left;
                this.quickMenu.top = top;
                this.quickMenu.dayISO = day.date;
                this.quickMenu.dayLabel = day.label;
                this.quickMenu.doctorId = doctorId;
                this.quickMenu.doctorLabel = (this.doctors.find(d => String(d.id) === String(doctorId))?.name ||
                    '');
                this.quickMenu.startTime = `${this.pad2(h)}:${this.pad2(m)}`;
                this.quickMenu.endTime = `${this.pad2(eh)}:${this.pad2(em)}`;
                this.quickMenu.timeText = `${this.pad2(h)}:${this.pad2(m)}`;
            },
            onWeekCellClick(e, day, doctorId) {
                const xView = e.clientX;
                const yView = e.clientY;
                const desiredLeft = xView + 8;
                const maxLeft = window.innerWidth - this.quickMenu.width - 8;
                const left = desiredLeft <= maxLeft ? desiredLeft : Math.max(8, xView - this.quickMenu.width -
                    8);

                const desiredTop = yView - this.quickMenu.height - 8;
                const minTop = 8;
                const maxTop = window.innerHeight - this.quickMenu.height - 8;
                const top = desiredTop >= minTop ? desiredTop : Math.min(maxTop, yView + 8);

                this.quickMenu.visible = true;
                this.quickMenu.left = left;
                this.quickMenu.top = top;
                this.quickMenu.dayISO = day.date;
                this.quickMenu.dayLabel = day.label;
                this.quickMenu.doctorId = doctorId;
                this.quickMenu.doctorLabel = (this.doctors.find(d => String(d.id) === String(doctorId))?.name ||
                    '');
                this.quickMenu.startTime = '09:00';
                this.quickMenu.endTime = '10:00';
                this.quickMenu.timeText = '09:00';
            },
            closeQuickMenu() {
                this.quickMenu.visible = false;
            },
            toggleDatePicker() {
                if (!this.datePickerOpen) {
                    const base = this.parseISODate(this.startISO);
                    this.datePickerMonthISO = this.toISO(new Date(base.getFullYear(), base.getMonth(), 1));
                }
                this.datePickerOpen = !this.datePickerOpen;
                this.closeQuickMenu();
            },
            openAppointmentModal() {
                this.modalError = '';
                this.modalSaving = false;
                this.modalContext = {
                    doctorId: this.quickMenu.doctorId,
                    doctorLabel: this.quickMenu.doctorLabel,
                    dayISO: this.quickMenu.dayISO,
                    dayLabel: this.quickMenu.dayLabel,
                    timeText: this.quickMenu.timeText,
                };
                this.appointmentForm.id = null;
                this.appointmentForm.doctor_id = this.quickMenu.doctorId;
                this.appointmentForm.startTime = this.quickMenu.startTime;
                this.appointmentForm.duration = this.appointmentForm.duration || 60;
                this.syncAppointmentEndTime();
                this.appointmentForm.reason = '';
                this.appointmentForm.lead_pipeline_stage_id = this.stages.length ? this.stages[0].id : null;
                this.appointmentForm.person = {
                    id: '',
                    name: ''
                };
                this.appointmentForm.product_id = null;
                this.appointmentForm.product_name = '';
                this.closeQuickMenu();
                this.$refs.appointmentModal.open();
            },
            openEditModal(ev) {
                this.modalError = '';
                this.modalSaving = false;

                // Get date object from event start
                const start = new Date(ev.start);
                const end = new Date(ev.end);

                // Setup modal context
                const isoDate = this.toISO(start);
                const dayLabel = this.days.find(d => d.date === isoDate)?.label || isoDate;

                this.modalContext = {
                    doctorId: ev.doctor_id,
                    doctorLabel: ev.doctor_name,
                    dayISO: isoDate,
                    dayLabel: dayLabel,
                    timeText: this.formatTime(ev.start),
                };

                // Populate form
                this.appointmentForm.id = ev.id;
                this.appointmentForm.doctor_id = ev.doctor_id;
                this.appointmentForm.startTime =
                    `${this.pad2(start.getHours())}:${this.pad2(start.getMinutes())}`;
                this.appointmentForm.endTime = `${this.pad2(end.getHours())}:${this.pad2(end.getMinutes())}`;
                this.syncAppointmentDurationFromTimes();

                this.appointmentForm.reason = ev.comment || '';
                this.appointmentForm.lead_pipeline_stage_id = ev.lead_pipeline_stage_id;
                this.appointmentForm.person = {
                    id: ev
                    .lead_id, // Note: using lead_id might be tricky if we need person_id directly, but let's assume we fetch person from lead or event
                    name: ev.person_name
                };

                // Set product info from event
                this.appointmentForm.product_id = ev.product_id || (ev.product ? ev.product.id : null);
                this.appointmentForm.product_name = ev.product_name || (ev.product ? ev.product.name : '');

                this.$refs.appointmentModal.open();
            },
            openViewModal(ev) {
                this.viewAppointment.data = ev;
                this.viewAppointment.visible = true;
                this.$refs.viewModal.open();
            },
            openGroupModal() {
                this.modalError = '';
                this.modalSaving = false;
                this.modalContext = {
                    doctorId: null,
                    doctorLabel: '',
                    dayISO: this.quickMenu.dayISO,
                    dayLabel: this.quickMenu.dayLabel,
                    timeText: this.quickMenu.timeText,
                };
                this.groupForm.title = '';
                this.groupForm.type = 'meeting';
                this.groupForm.startTime = this.quickMenu.startTime;
                this.groupForm.endTime = this.quickMenu.endTime;
                this.groupForm.doctorIds = this.quickMenu.doctorId ? [String(this.quickMenu.doctorId)] : [];
                this.closeQuickMenu();
                this.$refs.groupModal.open();
            },
            openUnavailableModal() {
                this.modalError = '';
                this.modalSaving = false;
                this.modalContext = {
                    doctorId: this.quickMenu.doctorId,
                    doctorLabel: this.quickMenu.doctorLabel,
                    dayISO: this.quickMenu.dayISO,
                    dayLabel: this.quickMenu.dayLabel,
                    timeText: this.quickMenu.timeText,
                };
                this.unavailableForm.startTime = this.quickMenu.startTime;
                this.unavailableForm.endTime = this.quickMenu.endTime;
                this.closeQuickMenu();
                this.$refs.unavailableModal.open();
            },
            saveAppointment() {
                this.modalSaving = true;
                this.modalError = '';

                if (!this.appointmentForm.person?.name) {
                    this.modalSaving = false;
                    this.modalError = 'Debes seleccionar un paciente.';
                    return;
                }

                if (!this.appointmentForm.doctor_id) {
                    this.modalSaving = false;
                    this.modalError = 'Debes seleccionar un doctor.';
                    return;
                }

                if (!this.appointmentForm.product_id) {
                    this.modalSaving = false;
                    this.modalError = 'Debes seleccionar un producto.';
                    return;
                }

                const payload = {
                    person: {
                        id: this.appointmentForm.person.id || null,
                        name: this.appointmentForm.person.name || '',
                    },
                    doctor_id: this.appointmentForm.doctor_id,
                    product_id: this.appointmentForm.product_id,
                    date: this.modalContext.dayISO,
                    start_time: this.appointmentForm.startTime,
                    end_time: this.appointmentForm.endTime,
                    duration_minutes: this.appointmentForm.duration,
                    reason: this.appointmentForm.reason || '',
                    lead_pipeline_stage_id: this.appointmentForm.lead_pipeline_stage_id,
                };

                let promise;
                if (this.appointmentForm.id) {
                    const url = "{{ route('admin.activities.update', 'replaceId') }}".replace('replaceId', this
                        .appointmentForm.id);
                    const startDateTime = `${this.modalContext.dayISO} ${this.appointmentForm.startTime}:00`;
                    const endDateTime = `${this.modalContext.dayISO} ${this.appointmentForm.endTime}:00`;

                    const updatePayload = {
                        schedule_from: startDateTime,
                        schedule_to: endDateTime,
                        comment: this.appointmentForm.reason,
                        product_id: this.appointmentForm.product_id,
                        doctor_id: this.appointmentForm.doctor_id,
                        lead_id: this.appointmentForm.person.id,
                        lead_pipeline_stage_id: this.appointmentForm.lead_pipeline_stage_id,
                    };
                    promise = this.$axios.put(url, updatePayload);
                } else {
                    promise = this.$axios.post(this.appointmentStoreUrl, payload);
                }

                promise
                    .then((response) => {
                        this.modalSaving = false;
                        this.$refs.appointmentModal.close();
                        this.fetch();
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response?.data?.message || (this.appointmentForm.id ?
                                'Cita actualizada correctamente.' : 'Cita creada correctamente.'
                                )
                        });
                    })
                    .catch(err => {
                        this.modalSaving = false;
                        this.modalError = err?.response?.data?.message || 'Error al guardar';
                    });
            },
            saveGroupAppointment() {
                this.modalSaving = true;
                this.modalError = '';

                const start = `${this.modalContext.dayISO} ${this.groupForm.startTime}`;
                const end = `${this.modalContext.dayISO} ${this.groupForm.endTime}`;
                const doctorIds = (this.groupForm.doctorIds || []).map(id => Number(id)).filter(Boolean);

                const payload = {
                    type: this.groupForm.type,
                    title: this.groupForm.title,
                    schedule_from: start,
                    schedule_to: end,
                    participants: {
                        doctors: doctorIds
                    },
                };

                this.$axios.post(this.storeUrl, payload)
                    .then(() => {
                        this.modalSaving = false;
                        this.$refs.groupModal.close();
                        this.fetch();
                    })
                    .catch(err => {
                        this.modalSaving = false;
                        this.modalError = err?.response?.data?.message || 'Error al guardar';
                    });
            },
            saveUnavailable() {
                this.modalSaving = true;
                this.modalError = '';

                this.$axios.post(this.scheduleStoreUrl, {
                        doctor_id: this.modalContext.doctorId,
                        date: this.modalContext.dayISO,
                        start_time: this.unavailableForm.startTime,
                        end_time: this.unavailableForm.endTime,
                    })
                    .then(() => {
                        this.modalSaving = false;
                        this.$refs.unavailableModal.close();
                        this.fetch();
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: 'Turno añadido correctamente'
                        });
                    })
                    .catch(err => {
                        this.modalSaving = false;
                        this.modalError = err?.response?.data?.message || 'Error al guardar';
                    });
            },
            openScheduleOptionsModal() {
                this.modalContext = {
                    doctorId: this.quickMenu.doctorId,
                    doctorLabel: this.quickMenu.doctorLabel,
                    dayISO: this.quickMenu.dayISO,
                    dayLabel: this.quickMenu.dayLabel,
                    timeText: this.quickMenu.timeText,
                };
                this.closeQuickMenu();
                this.$refs.scheduleOptionsModal.open();
            },
            openRecurringModal() {
                this.$refs.scheduleOptionsModal.close();
                this.modalError = '';
                this.modalSaving = false;

                const defaultStart = this.modalContext.dayISO || this.toISO(new Date());
                const defaultEnd = defaultStart;
                const defaultDays = [new Date(defaultStart).getDay() || 7];

                this.recurringForm.start_date = defaultStart;
                this.recurringForm.end_date = defaultEnd;
                this.recurringForm.days = defaultDays;
                this.recurringForm.time_slots = [{
                    startTime: this.quickMenu.startTime,
                    endTime: this.quickMenu.endTime
                }];

                this.$refs.recurringModal.open();
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
            openTimeOffModal() {
                this.$refs.scheduleOptionsModal.close();
                this.modalError = '';
                this.modalSaving = false;

                this.timeOffForm.doctor_id = this.modalContext.doctorId;
                this.timeOffForm.start_date = this.modalContext.dayISO || this.toISO(new Date());
                this.timeOffForm.start_time = this.quickMenu.startTime;
                this.timeOffForm.end_time = this.quickMenu.endTime;
                this.timeOffForm.description = '';
                this.timeOffForm.approved = false;
                this.timeOffForm.repeat = false;

                this.$refs.timeOffModal.open();
            },
            saveRecurring() {
                this.modalSaving = true;
                this.modalError = '';

                const shifts = this.recurringForm.time_slots.map(slot => ({
                    doctor_id: this.modalContext.doctorId || this.selectedDoctorIds[0],
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

                this.$axios.post(this.scheduleStoreUrl, payload)
                    .then((response) => {
                        this.modalSaving = false;
                        this.$refs.recurringModal.close();
                        this.fetch();
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message
                        });
                    })
                    .catch(err => {
                        this.modalSaving = false;
                        this.modalError = err?.response?.data?.message || 'Error al generar turnos';
                    });
            },
            saveTimeOff() {
                this.modalSaving = true;
                this.modalError = '';

                const start = `${this.timeOffForm.start_date} ${this.timeOffForm.start_time}`;
                const end = `${this.timeOffForm.start_date} ${this.timeOffForm.end_time}`;

                const payload = {
                    type: 'time_off',
                    title: `${this.timeOffForm.type}`,
                    schedule_from: start,
                    schedule_to: end,
                    participants: {
                        doctors: [this.timeOffForm.doctor_id]
                    },
                    comment: this.timeOffForm.description + (this.timeOffForm.approved ? ' [Aprobado]' :
                        ''),
                };

                this.$axios.post(this.storeUrl, payload)
                    .then(() => {
                        this.modalSaving = false;
                        this.$refs.timeOffModal.close();
                        this.fetch();
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: 'Días libres registrados'
                        });
                    })
                    .catch(err => {
                        this.modalSaving = false;
                        this.modalError = err?.response?.data?.message || 'Error al guardar';
                    });
            },
            calculateTimeOffDuration() {
                if (!this.timeOffForm.start_time || !this.timeOffForm.end_time) return '0 h';
                const s = this.timeToMinutes(this.timeOffForm.start_time);
                const e = this.timeToMinutes(this.timeOffForm.end_time);
                const diff = e - s;
                if (diff <= 0) return '0 h';
                const h = Math.floor(diff / 60);
                const m = diff % 60;
                return m > 0 ? `${h} h ${m} min` : `${h} h`;
            },
            editUrl(id) {
                return this.editUrlTemplate.replace('replaceId', id);
            },
            remove(ev) {
                this.$axios.delete(this.deleteUrlTemplate.replace('replaceId', ev.id))
                    .then(() => this.fetch())
                    .catch(() => {});
            },
            getDuration(start, end) {
                const s = new Date(start);
                const e = new Date(end);
                const diffMs = e - s;
                const diffMins = Math.round(diffMs / 60000);

                if (diffMins < 60) {
                    return `${diffMins} min`;
                }

                const h = Math.floor(diffMins / 60);
                const m = diffMins % 60;

                if (m === 0) {
                    return `${h} h`;
                }

                return `${h} h ${m} min`;
            },
            getStatusClass(status) {
                // If status is a stage ID (number/string), we might not have a specific class unless we map stages to colors.
                // For now, let's keep it simple or use a default class.
                // Or if 'is_done' is still used for legacy reasons, we keep it.
                // But user wants pipeline stages. Let's return a generic status class.
                return 'status-pipeline';
            },
            getStageName(id) {
                const s = this.stages.find(x => x.id == id);
                return s ? s.name : '';
            },
            formatPatientName(name) {
                if (!name) return 'Sin Paciente';
                const parts = name.trim().split(/\s+/);
                if (parts.length <= 1) return name;
                // Intenta tomar el primer nombre y el primer apellido (asumiendo que la segunda palabra es el apellido o parte del nombre compuesto)
                // Para cumplir estrictamente "1 nombre y 1 apellido", tomaremos la primera palabra y la segunda palabra.
                return `${parts[0]} ${parts[1]}`;
            },
            updateStatus(ev) {
                if (!ev.lead_id || !ev.lead_pipeline_stage_id) return;

                const url = "{{ route('admin.leads.stage.update', 'replaceId') }}".replace('replaceId', ev
                    .lead_id);

                this.$axios.put(url, {
                        lead_pipeline_stage_id: ev.lead_pipeline_stage_id
                    })
                    .then(() => {
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: 'Estado actualizado'
                        });
                    })
                    .catch(() => {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: 'Error al actualizar estado'
                        });
                    });
            },
        },
    });
</script>

<style>
    .ddc-date-overlay {
        position: fixed;
        inset: 0;
        background: rgba(17, 24, 39, 0.08);
        backdrop-filter: blur(2px);
        z-index: 9998;
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 90px 12px
    }

    .ddc-date-panel {
        width: min(860px, 96vw);
        background: var(--hours-bg, #fff);
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: 16px;
        box-shadow: 0 24px 60px rgba(0, 0, 0, 0.18);
        padding: 18px;
        z-index: 9999
    }

    .ddc-date-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px
    }

    .ddc-date-nav {
        width: 36px;
        height: 36px;
        border-radius: 9999px;
        border: 1px solid var(--border-color, #e5e7eb);
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent
    }

    .ddc-date-months-header {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        flex: 1;
        text-align: center
    }

    .ddc-date-month-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--day-text, #111827)
    }

    .ddc-date-months {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px
    }

    .ddc-date-weekdays {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 6px;
        margin-bottom: 10px;
        color: var(--hour-text, #6b7280);
        font-size: 13px
    }

    .ddc-date-weekday {
        text-align: center;
        text-transform: lowercase
    }

    .ddc-date-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 6px
    }

    .ddc-date-day {
        height: 42px;
        border-radius: 9999px;
        border: 1px solid transparent;
        background: transparent;
        font-size: 14px;
        color: var(--day-text, #111827);
        display: flex;
        align-items: center;
        justify-content: center
    }

    .ddc-date-day.is-out {
        color: transparent
    }

    .ddc-date-day:disabled {
        cursor: default
    }

    .ddc-date-day:not(:disabled):hover {
        background: rgba(103, 80, 164, 0.10);
        border-color: rgba(103, 80, 164, 0.35)
    }

    .ddc-date-day.is-selected {
        background: rgba(103, 80, 164, 0.18);
        border-color: #6750A4;
        color: #6750A4;
        font-weight: 700
    }

    .ddc-date-day.is-today {
        outline: 2px solid rgba(239, 68, 68, 0.35)
    }

    .ddc-quick-overlay {
        position: fixed;
        inset: 0;
        z-index: 9998;
        background: transparent
    }

    .ddc-quick-menu {
        position: fixed;
        background: var(--hours-bg, #fff);
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: 12px;
        box-shadow: 0 16px 40px rgba(0, 0, 0, 0.18);
        z-index: 9999;
        overflow: hidden
    }

    .ddc-quick-menu-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #d1d5db;
        padding: 10px 12px
    }

    .dark .ddc-quick-menu-head {
        background: #374151
    }

    .ddc-quick-menu-time {
        font-weight: 700;
        color: #111827
    }

    .dark .ddc-quick-menu-time {
        color: #f3f4f6
    }

    .ddc-quick-menu-close {
        width: 26px;
        height: 26px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 0;
        background: transparent;
        font-size: 18px;
        line-height: 1;
        color: inherit
    }

    .ddc-quick-menu-list {
        display: flex;
        flex-direction: column;
        padding: 8px
    }

    .ddc-quick-menu-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 8px;
        border-radius: 10px;
        background: transparent;
        border: 0;
        text-align: left
    }

    .ddc-quick-menu-item:hover {
        background: rgba(103, 80, 164, 0.10)
    }

    .ddc-quick-menu-link {
        padding: 10px 8px;
        color: #6750A4;
        font-size: 14px
    }

    .dwc-now-line {
        position: absolute;
        left: 0;
        right: 0;
        border-top: 2px solid #ef4444;
        z-index: 40;
        pointer-events: none
    }

    .dwc-now-time {
        position: absolute;
        left: 6px;
        top: -12px;
        background: var(--hours-bg, #fff);
        border: 1px solid #ef4444;
        color: #ef4444;
        font-size: 12px;
        line-height: 1;
        padding: 2px 8px;
        border-radius: 9999px
    }

    .ms-container {
        display: inline-block;
        min-width: 240px;
        position: relative
    }

    .ms-input {
        display: flex;
        align-items: center;
        justify-content: space-between;
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: 8px;
        padding: 6px;
        gap: 6px;
        background: var(--hours-bg, #fff)
    }

    .dark .ms-input {
        border-color: #1f2937;
        background: #0b0f19
    }

    .ms-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        max-height: 64px;
        overflow: auto
    }

    .ms-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f3f4f6;
        border-radius: 12px;
        padding: 2px 8px;
        font-size: 12px
    }

    .dark .ms-chip {
        background: #262b36
    }

    .ms-chip-x {
        cursor: pointer
    }

    .ms-actions {
        display: flex;
        align-items: center;
        gap: 8px
    }

    .ms-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 6px;
        background: #f3f4f6
    }

    .dark .ms-count {
        background: #262b36
    }

    .ms-dropdown {
        position: fixed;
        left: 0;
        right: 0;
        top: calc(100% + 4px);
        border: 1px solid var(--border-color, #e5e7eb);
        background: var(--hours-bg, #fff);
        border-radius: 8px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        padding: 8px;
        z-index: 100001
    }

    .dark .ms-dropdown {
        border-color: #1f2937;
        background: #0b0f19
    }

    .ms-search {
        width: 100%;
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: 6px;
        padding: 6px 8px;
        font-size: 12px;
        margin-bottom: 8px
    }

    .dark .ms-search {
        border-color: #1f2937;
        background: #0b0f19;
        color: #cbd5e1
    }

    .ms-list {
        max-height: 180px;
        overflow: auto
    }

    .ms-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px;
        border-radius: 6px;
        cursor: pointer
    }

    .ms-item:hover {
        background: #f3f4f6
    }

    .dark .ms-item:hover {
        background: #262b36
    }

    .ms-empty {
        padding: 6px;
        color: #6b7280;
        font-size: 12px
    }

    .dwc-container {
        display: flex;
        flex-direction: column;
        gap: 8px;
        width: 100%
    }

    .dwc-scroll-container {
        max-height: 70vh; /* O la altura que prefieras */
        overflow: auto;
    }

    .dwc-doctor-header {
        position: -webkit-sticky;
        position: sticky;
        top: 0;
        z-index: 20;
    }

    .dwc-time-col {
        position: -webkit-sticky;
        position: sticky;
        left: 0;
        z-index: 30; /* Mayor que el de las cabeceras */
        background: var(--hours-bg, #fff);
    }

    .dwc-controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 8px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--controls-bg);
        flex-wrap: wrap
    }

    .dwc-filters {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap
    }

    /* Buscador de pacientes */
    .ddc-buscador {
        position: relative;
        z-index: 41;
    }

    .ddc-buscador-input {
        width: 280px;
        max-width: 100%;
        padding: 6px 10px;
        font-size: 13px;
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: 6px;
        background: var(--controls-bg, #fff);
        color: inherit;
    }

    .ddc-buscador-overlay {
        position: fixed;
        inset: 0;
        z-index: 40;
    }

    .ddc-buscador-panel {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        width: 360px;
        max-width: 90vw;
        max-height: 420px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, .12);
        padding: 6px;
    }

    .dark .ddc-buscador-panel {
        background: #111827;
        border-color: #374151;
    }

    .ddc-buscador-paciente {
        padding: 8px;
        border-bottom: 1px solid #f3f4f6;
    }

    .dark .ddc-buscador-paciente {
        border-color: #1f2937;
    }

    .ddc-buscador-paciente:last-child {
        border-bottom: 0;
    }

    .ddc-buscador-nombre {
        font-weight: 600;
        font-size: 14px;
    }

    .ddc-buscador-datos,
    .ddc-buscador-vacio {
        font-size: 12px;
        color: #6b7280;
        padding: 2px 0;
    }

    .ddc-buscador-cita {
        display: flex;
        width: 100%;
        align-items: center;
        gap: 8px;
        margin-top: 4px;
        padding: 6px 8px;
        border-radius: 6px;
        font-size: 12px;
        text-align: left;
        background: #eff6ff;
        color: #1e3a8a;
    }

    .ddc-buscador-cita:hover {
        background: #dbeafe;
    }

    .ddc-buscador-cita.is-pasada {
        background: #f9fafb;
        color: #4b5563;
    }

    .ddc-buscador-doctor {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ddc-buscador-tag {
        padding: 1px 6px;
        border-radius: 9999px;
        background: #2563eb;
        color: #fff;
        font-size: 11px;
    }

    .dwc-grid {
        display: grid;
        gap: 0;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        overflow: auto;
        background: var(--events-bg);
        position: relative
    }

    .dwc-hours {
        border-right: 1px solid var(--border-color);
        background: var(--hours-bg)
    }

    .dwc-hour-row {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding-right: 8px;
        height: var(--hour-height);
        font-size: 12px;
        color: var(--hour-text)
    }

    .dwc-doctor-col {
        border-right: 1px solid var(--border-color);
    }

    .dwc-doctor-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px;
        font-size: 12px;
        color: var(--day-text);
        border-bottom: 1px solid var(--border-color);
        background: var(--hours-bg)
    }

    .dwc-days-stack {
        position: relative;
        background: #ecececff;
    }

    .dark .dwc-days-stack {
        background: #111827;
    }

    .dwc-availability-block {
        position: absolute;
        left: 0;
        right: 0;
        background: var(--hours-bg, #fff);
        z-index: 0;
    }

    .dwc-day-block {
        position: relative;
        border-bottom: 1px solid var(--border-color);
        cursor: pointer;
    }

    .dwc-hour-line {
        position: absolute;
        left: 0;
        right: 0;
        height: 1px;
        background: var(--grid-line);
        z-index: 1;
        pointer-events: none;
    }

    .dwc-event {
        position: absolute;
        left: 6px;
        right: 6px;
        border-left: 4px solid var(--event-accent);
        background: var(--event-bg);
        color: var(--event-text);
        border-radius: 6px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        font-size: 12px;
        z-index: 2;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        cursor: pointer;
        transition: transform 0.1s, box-shadow 0.1s;
        height: auto;
        /* Allow growth */
    }

    .dwc-event:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.15);
        z-index: 10;
    }

    .dwc-event-content {
        display: flex;
        flex-direction: column;
        gap: 3px;
        height: 100%;
        position: relative;
        padding: 6px 8px;
        box-sizing: border-box;
    }

    .dwc-event-actions-wrapper {
        position: relative;
        z-index: 10;
    }

    .dwc-event-actions {
        position: absolute;
        top: -36px;
        right: 0;
        display: none;
        gap: 4px;
        z-index: 5;
    }

    .dwc-event:hover .dwc-event-actions {
        display: flex;
    }

    .dwc-action-btn {
        width: 20px;
        height: 20px;
        border-radius: 4px;
        background: #fff;
        border: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6b7280;
        cursor: pointer;
        font-size: 12px;
    }

    .dwc-action-btn:hover {
        background: #f3f4f6;
        color: #111827;
    }

    .dark .dwc-action-btn {
        background: #1f2937;
        border-color: #374151;
        color: #9ca3af;
    }

    .dark .dwc-action-btn:hover {
        background: #374151;
        color: #f3f4f6;
    }

    .dwc-event-info {
        flex-grow: 1;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .dwc-event-row-primary {
        display: flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
        overflow: hidden;
        width: 100%;
    }

    .dwc-event-row-secondary {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
        color: #4b5563;
        white-space: nowrap;
        overflow: hidden;
        width: 100%;
    }

    .dwc-event-separator {
        color: #9ca3af;
        font-size: 10px;
    }

    .dwc-event-status-text {
        font-weight: 600;
        text-transform: uppercase;
        font-size: 9px;
        color: #374151;
        background: rgba(0, 0, 0, 0.05);
        padding: 1px 3px;
        border-radius: 3px;
    }

    .dwc-event-patient {
        font-weight: 700;
        font-size: 12px;
        color: #111827;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        flex-shrink: 0;
        max-width: 60%;
    }

    .dwc-event-doctor {
        font-size: 11px;
        color: #4b5563;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-style: italic;
        flex-shrink: 1;
    }

    .dwc-event-reason {
        font-size: 11px;
        color: #6b7280;
        margin-top: 2px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        line-height: 1.3;
        font-style: italic;
    }



    .dwc-add-overlay {
        position: fixed;
        background: var(--hours-bg);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 8px;
        z-index: 9999;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18)
    }

    :root {
        --hour-height: 64px;
        --border-color: #e5e7eb;
        --grid-line: #eef2f7;
        --hours-bg: #fff;
        --events-bg: #fff;
        --hour-text: #6b7280;
        --day-text: #111827;
        --event-bg: #f8fafc;
        --event-text: #111827;
        --event-accent: #3b82f6;
        --controls-bg: #fff
    }

    .dark .dwc-grid,
    .dark .dwc-controls {
        --border-color: #1f2937;
        --grid-line: #101828;
        --hours-bg: #0b0f19;
        --events-bg: #0b0f19;
        --hour-text: #cbd5e1;
        --day-text: #f3f4f6;
        --event-bg: #111827;
        --event-text: #f3f4f6;
        --event-accent: #60a5fa;
        --controls-bg: #0b0f19
    }

    @media (max-width:640px) {
        :root {
            --hour-height: 48px
        }
    }

    @media (max-width:900px) {
        .ddc-date-months {
            grid-template-columns: 1fr
        }

        .ddc-date-months-header {
            grid-template-columns: 1fr
        }

        .ddc-date-panel {
            padding: 14px
        }

        .ddc-date-month-title {
            font-size: 16px
        }
    }

    .dwc-month-view {
        display: flex;
        flex-direction: column;
        gap: 10px;
        background: var(--hours-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 10px;
    }

    .dwc-month-cell {
        height: 120px;
        align-items: flex-start;
        justify-content: flex-start;
        flex-direction: column;
        border-radius: 8px;
        border: 1px solid var(--border-color);
    }

    .dwc-month-cell-header {
        width: 100%;
        display: flex;
        justify-content: space-between;
        padding: 4px 8px;
    }

    .dwc-month-cell-events {
        width: 100%;
        padding: 0 4px;
        overflow-y: auto;
        max-height: 90px;
    }

    .dwc-month-event {
        font-size: 11px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        margin-bottom: 4px;
        padding: 2px 4px;
        border-radius: 4px;
        background: #dbeafe;
        color: #1e40af;
        cursor: pointer;
    }

    .dark .dwc-month-event {
        background: #1e3a8a;
        color: #dbeafe;
    }

    .dwc-week-view {
        display: flex;
        flex-direction: column;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--hours-bg);
        overflow: auto;
    }

    .dwc-week-header {
        display: grid;
        grid-template-columns: 150px repeat(7, minmax(120px, 1fr));
        border-bottom: 1px solid var(--border-color);
        background: var(--gray-50);
    }

    .dark .dwc-week-header {
        background: var(--hours-bg);
    }

    .dwc-week-header-cell {
        padding: 10px;
        text-align: center;
        font-weight: 600;
        border-right: 1px solid var(--border-color);
        font-size: 13px;
        color: var(--day-text);
    }

    .dwc-week-header-cell.doctor-col {
        text-align: left;
        padding-left: 16px;
    }

    .dwc-week-body {
        display: flex;
        flex-direction: column;
    }

    .dwc-week-row {
        display: grid;
        grid-template-columns: 150px repeat(7, minmax(120px, 1fr));
        border-bottom: 1px solid var(--border-color);
        min-height: 80px;
    }

    .dwc-week-doctor-cell {
        padding: 10px;
        border-right: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
        justify-content: center;
        background: var(--gray-50);
        color: var(--day-text);
    }

    .dark .dwc-week-doctor-cell {
        background: var(--hours-bg);
    }

    .dwc-week-day-cell {
        padding: 4px;
        border-right: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
        gap: 4px;
        cursor: pointer;
    }

    .dwc-week-day-cell:hover {
        background-color: rgba(0, 0, 0, 0.02);
    }

    .dwc-week-day-cell.is-today {
        background-color: rgba(59, 130, 246, 0.05);
    }

    .dwc-week-event {
        font-size: 11px;
        background: #e0f2fe;
        color: #0369a1;
        padding: 2px 4px;
        border-radius: 4px;
        cursor: pointer;
        display: flex;
        gap: 4px;
        align-items: center;
        overflow: hidden;
    }

    .dark .dwc-week-event {
        background: #075985;
        color: #e0f2fe;
    }

    @media (max-width: 768px) {
        .dwc-event-actions-wrapper {
            display: none;
        }
    }

    .tp-container {
        position: relative;
        display: inline-block;
        width: 100%;
    }

    .tp-input {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        border: 1px solid #e5e7eb;
        border-radius: 0.375rem;
        padding: 0.5rem;
        font-size: 0.875rem;
        background: white;
        cursor: pointer;
        height: 39px;
    }

    .dark .tp-input {
        border-color: #374151;
        background: #1f2937;
        color: #d1d5db;
    }

    .tp-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 0.375rem;
        z-index: 10004;
        margin-top: 4px;
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    }

    .dark .tp-dropdown {
        border-color: #374151;
        background: #1f2937;
    }

    .tp-lists {
        display: flex;
        height: 160px;
    }

    .tp-list {
        list-style: none;
        margin: 0;
        padding: 4px;
        overflow-y: auto;
        flex: 1;
        border-right: 1px solid #e5e7eb;
    }

    .dark .tp-list {
        border-right-color: #374151;
    }

    .tp-list:last-child {
        border-right: none;
    }

    .tp-list li {
        padding: 4px 8px;
        border-radius: 0.25rem;
        cursor: pointer;
        text-align: center;
    }

    .tp-list li:hover {
        background-color: #f3f4f6;
    }

    .dark .tp-list li:hover {
        background-color: #374151;
    }

    .tp-list li.is-selected {
        background-color: #3b82f6;
        color: white;
        font-weight: bold;
    }

    .dark .tp-list li.is-selected {
        background-color: #2563eb;
    }
</style>
