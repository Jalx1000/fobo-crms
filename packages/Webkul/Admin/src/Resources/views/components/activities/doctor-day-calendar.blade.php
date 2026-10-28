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
                <button class="px-2 py-1 rounded border dark:border-gray-800" aria-label="Período anterior" @click="prevPeriod">←</button>
                <button class="px-2 py-1 rounded border dark:border-gray-800" @click="goToday">Hoy</button>
                <button
                    type="button"
                    class="px-3 py-1 rounded border dark:border-gray-800 text-sm font-semibold dark:text-white"
                    @click="toggleDatePicker"
                >
                    @{{ dayLabel }}
                </button>
                <button class="px-2 py-1 rounded border dark:border-gray-800" aria-label="Período siguiente" @click="nextPeriod">→</button>
            </div>

            <div class="dwc-filters">
                <span class="text-xs dark:text-gray-300">Filtrar doctores:</span>
                <button type="button" class="px-2 py-1 rounded border text-xs dark:border-gray-800" @click="selectAllDoctors">Todos</button>
                <button type="button" class="px-2 py-1 rounded border text-xs dark:border-gray-800" @click="clearDoctors">Ninguno</button>
                <v-doctor-multiselect style="max-width:350px;min-width:350px;max-height:100%" :items="doctors" v-model="selectedDoctorIds"></v-doctor-multiselect>

                <!-- Buscador de paciente: nombre, teléfono o CI (PatientSearch en el backend) -->
                <!-- Estilos en línea: clases de Tailwind nuevas no están en el CSS compilado de producción. -->
                <div class="flex items-center" style="position:relative">
                    <span class="icon-search text-xl text-gray-400" style="position:absolute;left:8px;pointer-events:none"></span>
                    <input
                        type="search"
                        class="rounded border border-gray-200 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
                        style="width:17rem;padding-left:2.1rem;padding-right:.5rem"
                        placeholder="Buscar paciente: nombre, teléfono o CI"
                        aria-label="Buscar paciente por nombre, teléfono o CI"
                        v-model="patientSearch"
                        @input="onPatientSearch"
                    >
                </div>
                
                <div class="flex items-center rounded border dark:border-gray-800 overflow-hidden bg-white dark:bg-gray-900">
                    <button type="button" class="px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-800" :class="viewType==='day'?'bg-gray-100 dark:bg-gray-800 font-semibold text-blue-600':''" @click="setView('day')">Día</button>
                    <button type="button" class="px-3 py-2 text-sm border-l dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800" :class="viewType==='week'?'bg-gray-100 dark:bg-gray-800 font-semibold text-blue-600':''" @click="setView('week')">Semana</button>
                    <button type="button" class="px-3 py-2 text-sm border-l dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800" :class="viewType==='month'?'bg-gray-100 dark:bg-gray-800 font-semibold text-blue-600':''" @click="setView('month')">Mes</button>
                    <button type="button" class="px-3 py-2 text-sm border-l dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800" :class="viewType==='list'?'bg-gray-100 dark:bg-gray-800 font-semibold text-blue-600':''" @click="setView('list')">Lista</button>
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
                <button type="button" class="ddc-quick-menu-close" aria-label="Cerrar menú" @click="closeQuickMenu">×</button>
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
                    <button type="button" class="ddc-date-nav" aria-label="Mes anterior" @click="shiftPicker(-1)">‹</button>
                    <div class="ddc-date-months-header">
                        <div class="ddc-date-month-title">@{{ monthTitle(month1Date) }}</div>
                        <div class="ddc-date-month-title">@{{ monthTitle(month2Date) }}</div>
                    </div>
                    <button type="button" class="ddc-date-nav" aria-label="Mes siguiente" @click="shiftPicker(1)">›</button>
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
                              :class="{ 'is-cancelled': ev.is_cancelled, 'is-completed': ev.is_completed, 'is-confirmed': ev.is_confirmed, 'is-surgery': ev.is_surgery, 'is-lost': ev.is_lost }"
                              :title="ev.title || ev.type"
                              @click.stop="openViewModal(ev)"
                              @mouseenter="onEventMouseEnter($event, ev.id, ev)"
                              @mouseleave="hoveredEventId = null; hoveredEvent = null"
                         >
                             <span class="font-semibold">@{{ formatTime(ev.start) }}</span> @{{ ev.title || ev.type }}
                         </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="viewType === 'list'" class="dwc-list-view">
            <!-- Filtro de rango de fechas Desde / Hasta -->
            <div class="dwc-list-filters">
                <div class="flex items-end gap-3 flex-wrap">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-gray-600 dark:text-gray-300">Desde</span>
                        <input type="date" class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="rangeStart" @change="applyRange">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-gray-600 dark:text-gray-300">Hasta</span>
                        <input type="date" class="rounded border border-gray-200 px-2.5 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="rangeEnd" @change="applyRange">
                    </label>
                    <button type="button" class="primary-button" @click="applyRange">Aplicar</button>
                    <span class="text-xs text-gray-500 dark:text-gray-400 ml-1">@{{ listAppointments.length }} citas</span>
                </div>
            </div>

            <div v-if="isLoading" class="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Cargando…</div>

            <template v-else>
                <div v-if="!listAppointments.length" class="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    No hay citas en el rango seleccionado.
                </div>

                <div v-else class="dwc-list-table">
                    <div v-for="group in listGroups" :key="'lg-'+group.date" class="dwc-list-group">
                        <div class="dwc-list-group-title">@{{ group.label }}</div>

                        <div v-for="ev in group.items" :key="'lrow-'+ev.id" class="dwc-list-row"
                             :class="{ 'is-cancelled': ev.is_cancelled, 'is-completed': ev.is_completed, 'is-confirmed': ev.is_confirmed, 'is-surgery': ev.is_surgery, 'is-lost': ev.is_lost }"
                             role="button" tabindex="0" style="cursor:pointer"
                             @click="openViewModal(ev)" @keydown.enter="openViewModal(ev)">
                            <div class="dwc-list-time">
                                <span class="font-semibold">@{{ formatTime(ev.start) }}</span>
                                <span class="text-gray-400"> - @{{ formatTime(ev.end) }}</span>
                            </div>
                            <div class="dwc-list-main">
                                <div class="text-sm font-semibold dark:text-white">@{{ ev.person_name || ev.title || 'Cita' }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    <span v-if="ev.doctor_name">@{{ ev.doctor_name }}</span>
                                    <span v-if="ev.product_name"> · @{{ ev.product_name }}</span>
                                    <span> · @{{ getStageName(ev.lead_pipeline_stage_id) || ev.type }}</span>
                                </div>
                            </div>
                            <div class="dwc-list-actions">
                                <button type="button" class="dwc-action-btn" title="Ver detalles" aria-label="Ver detalles de la cita" @click="openViewModal(ev)">
                                    <span class="icon-eye"></span>
                                </button>
                                <button type="button" class="dwc-action-btn" title="Editar Cita" aria-label="Editar la cita" @click="openEditModal(ev)">
                                    <span class="icon-edit"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
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
                        <span class="text-xs text-gray-500">@{{ totalCount(col.id) }} citas</span>
                    </div>
                    <div v-for="day in days" :key="'wcell-'+col.id+'-'+day.date" 
                         class="dwc-week-day-cell"
                         :class="{ 'is-today': day.date === todayISO() }"
                         @click="onWeekCellClick($event, day, col.id)"
                    >
                        <div v-for="ev in dayDoctorEvents(day.date, col.id)" :key="'wev-'+ev.id"
                             class="dwc-week-event"
                             :class="{ 'dwc-event-cancelled': ev.is_cancelled, 'dwc-event-completed': ev.is_completed, 'dwc-event-confirmed': ev.is_confirmed, 'dwc-event-surgery': ev.is_surgery, 'dwc-event-lost': ev.is_lost }"
                             :title="ev.title || ev.type"
                             @click.stop="openViewModal(ev)"
                             @mouseenter="onEventMouseEnter($event, ev.id, ev)"
                             @mouseleave="hoveredEventId = null; hoveredEvent = null"
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
                        <div v-for="ev in dayDoctorEvents(day.date, columns[0].id)" :key="'ev-sd-'+ev.id" class="dwc-event" :class="{ 'dwc-event-cancelled': ev.is_cancelled, 'dwc-event-completed': ev.is_completed, 'dwc-event-confirmed': ev.is_confirmed, 'dwc-event-surgery': ev.is_surgery, 'dwc-event-lost': ev.is_lost, 'dwc-event-compact': ev.height < 64, 'dwc-event-tiny': ev.height < 40 }" :style="{ top: ev.top + 'px', height: ev.height + 'px', left: 'calc(' + ev.left + '% + 6px)', width: 'calc(' + ev.width + '% - 12px)' }" @click.stop="openViewModal(ev)" @mouseenter="onEventMouseEnter($event, ev.id, ev)" @mouseleave="hoveredEventId = null; hoveredEvent = null">
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
                                        <button type="button" class="dwc-action-btn" title="Ver Detalles" aria-label="Ver detalles de la cita" @click="openViewModal(ev)">
                                            <span class="icon-eye text-lg"></span>
                                        </button>
                                        <button type="button" class="dwc-action-btn" title="Editar Cita" aria-label="Editar la cita" @click="openEditModal(ev)">
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

                            <div v-for="ev in dayDoctorEvents(day.date, col.id)" :key="'ev-'+ev.id" class="dwc-event" :class="{ 'dwc-event-cancelled': ev.is_cancelled, 'dwc-event-completed': ev.is_completed, 'dwc-event-confirmed': ev.is_confirmed, 'dwc-event-surgery': ev.is_surgery, 'dwc-event-lost': ev.is_lost, 'dwc-event-compact': ev.height < 64, 'dwc-event-tiny': ev.height < 40 }" :style="{ top: ev.top + 'px', height: ev.height + 'px', left: 'calc(' + ev.left + '% + 6px)', width: 'calc(' + ev.width + '% - 12px)' }" @click.stop="openViewModal(ev)" @mouseenter="onEventMouseEnter($event, ev.id, ev)" @mouseleave="hoveredEventId = null; hoveredEvent = null">
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
                                            <button type="button" class="dwc-action-btn" title="Ver Detalles" aria-label="Ver detalles de la cita" @click="openViewModal(ev)">
                                                <span class="icon-eye text-lg"></span>
                                            </button>
                                            <button type="button" class="dwc-action-btn" title="Editar Cita" aria-label="Editar la cita" @click="openEditModal(ev)">
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
                <div>
                    <h3 class="text-lg font-semibold dark:text-white">Nueva cita</h3>
                    <p class="mt-0.5 flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                        <span class="icon-calendar text-base text-brandColor"></span>
                        @{{ modalContext.dayLabel }} · @{{ modalContext.timeText }}
                    </p>
                </div>
            </x-slot>

            <x-slot:content>
                <div class="flex flex-col gap-4">
                    <!-- Paciente -->
                    <div class="flex flex-col gap-1">
                        <x-admin::form.control-group.label class="required">Paciente</x-admin::form.control-group.label>
                        <x-admin::lookup
                            ::src="personSearchUrl"
                            ::params="{ limit: 200 }"
                            name="appointment_person"
                            placeholder="Buscar o crear paciente…"
                            :can-add-new="true"
                            @on-selected="onSelectPatient"
                            ::value="{ id: appointmentForm.person.id, name: appointmentForm.person.name }"
                        />
                    </div>

                    <!-- Datos de la cita -->
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                        <p class="mb-2.5 text-xs font-semibold uppercase tracking-wide text-gray-400">Datos de la cita</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Sucursales</x-admin::form.control-group.label>
                                <v-doctor-multiselect :items="doctors" v-model="appointmentForm.doctor_ids"></v-doctor-multiselect>
                            </div>

                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Servicio</x-admin::form.control-group.label>
                                <x-admin::lookup
                                    ::src="productSearchUrl"
                                    ::params="{ limit: 200 }"
                                    name="appointment_product"
                                    placeholder="Buscar servicio…"
                                    @on-selected="onSelectService"
                                    ::value="{ id: appointmentForm.product_id, name: appointmentForm.product_name }"
                                />
                            </div>

                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Hora de inicio</x-admin::form.control-group.label>
                                <v-time-picker
                                    v-model="appointmentForm.startTime"
                                    @update:modelValue="syncAppointmentEndTime"
                                ></v-time-picker>
                            </div>

                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Duración</x-admin::form.control-group.label>
                                <select
                                    class="custom-select w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
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
                                <x-admin::form.control-group.label>Hora de fin</x-admin::form.control-group.label>
                                <v-time-picker
                                    v-model="appointmentForm.endTime"
                                    :disabled="true"
                                ></v-time-picker>
                            </div>

                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Estado</x-admin::form.control-group.label>
                                <select
                                    class="custom-select w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                                    v-model="appointmentForm.lead_pipeline_stage_id"
                                >
                                    <option :value="null" disabled>Seleccionar estado</option>
                                    <option v-for="stage in stages" :key="'st-modal-'+stage.id" :value="stage.id">@{{ stage.name }}</option>
                                </select>
                            </div>

                            <div class="col-span-2 flex flex-col gap-1">
                                <x-admin::form.control-group.label>Motivo de consulta</x-admin::form.control-group.label>
                                <textarea
                                    rows="3"
                                    class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                    v-model="appointmentForm.reason"
                                    placeholder="Motivo de consulta (opcional)"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <div v-if="appointmentForm.person.id" class="col-span-2 border rounded-lg p-3 dark:border-gray-700">
                        <div class="text-xs font-semibold text-gray-600 dark:text-gray-300 mb-2">Seguro médico</div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>CI del paciente</x-admin::form.control-group.label>
                                <input
                                    type="text"
                                    class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                    v-model="insurance.ci"
                                    placeholder="Cedula de identidad"
                                />
                            </div>
                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Tipo de seguro</x-admin::form.control-group.label>
                                <select
                                    class="custom-select w-full rounded border border-gray-200 px-2.5 py-2 text-sm font-normal text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                                    v-model="insurance.seguro_id"
                                >
                                    <option :value="null" disabled>Seleccionar</option>
                                    <option v-for="opt in insuranceOptions" :key="'ins-'+opt.id" :value="opt.id">@{{ opt.name }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 mt-2">
                            <button
                                type="button"
                                class="text-xs px-3 py-1.5 rounded border dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center gap-1"
                                @click="verifyInsurance"
                                :disabled="insurance.verifying || !insurance.ci || !insurance.seguro_id"
                            >
                                <x-admin::spinner v-if="insurance.verifying" class="w-3 h-3" />
                                <span>Verificar seguro</span>
                            </button>
                            <span
                                v-if="insurance.result"
                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                :class="insuranceBadgeClass(insurance.result.badge)"
                            >
                                @{{ insurance.result.status }} - @{{ insurance.result.message }}
                            </span>
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
                                <input type="date" class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400" v-model="recurringForm.start_date" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs font-medium text-gray-500">Fecha de Fin</label>
                                <input type="date" class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400" v-model="recurringForm.end_date" />
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
                            <select class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400" v-model="timeOffForm.doctor_id">
                                <option v-for="d in doctors" :key="'to-d-'+d.id" :value="d.id">@{{ d.name }}</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-gray-500">Tipo</label>
                            <select class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400" v-model="timeOffForm.type">
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
                            <input type="date" class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400" v-model="timeOffForm.start_date" />
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
                        <textarea class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400" rows="3" v-model="timeOffForm.description" placeholder="Añadir descripción o comentario (opcional)"></textarea>
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
                    <button type="button" class="primary-button" @click="saveTimeOff" :disabled="modalSaving">
                        <span v-if="!modalSaving">Guardar</span>
                        <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando...</span>
                    </button>
                </div>
            </x-slot>
        </x-admin::modal>

        <x-admin::modal ref="viewModal" size="large">
            <x-slot:header>
                <div class="text-lg font-semibold dark:text-white">
                    Detalles de la Cita
                </div>
            </x-slot>

            <x-slot:content>
                <div v-if="viewAppointment.data" class="flex flex-col gap-5 lg:h-[72vh] lg:flex-row">
                  <!-- Columna izquierda: detalle / edición de la cita -->
                  <div class="flex flex-col gap-4 lg:w-1/2 lg:min-w-0 lg:overflow-y-auto lg:pr-1">
                    <template v-if="!editMode">
                    <!-- Cabecera: paciente + estado -->
                    <div class="flex items-start justify-between gap-3 border-b border-gray-200 pb-3 dark:border-gray-800">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-base font-semibold"
                                 :class="avatarColor(viewAppointment.data.person_name)">
                                @{{ apptInitials(viewAppointment.data.person_name) }}
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-base font-bold text-gray-900 dark:text-white">@{{ viewAppointment.data.person_name || 'Paciente' }}</p>
                                    <button type="button" v-if="!editingPatient" @click="editingPatient = true"
                                            class="shrink-0 text-xs text-blue-600 hover:underline dark:text-blue-400">Cambiar</button>
                                </div>
                                <p v-if="viewAppointment.data.person_whatsapp && !editingPatient" class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="icon-call text-sm"></span> @{{ viewAppointment.data.person_whatsapp }}
                                </p>
                                <div v-if="editingPatient" class="mt-1">
                                    <div class="flex items-center gap-2">
                                        <input type="text" v-model="patientQuery" @input="searchPatients"
                                               placeholder="Buscar paciente por nombre…"
                                               class="w-full rounded border border-gray-200 px-2.5 py-1.5 text-sm text-gray-800 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                        <button type="button" @click="cancelEditPatient"
                                                class="shrink-0 text-xs text-gray-500 hover:underline dark:text-gray-400">Cancelar</button>
                                    </div>
                                    <div v-if="patientResults.length" class="mt-1 max-h-40 overflow-auto rounded border border-gray-200 dark:border-gray-800">
                                        <button v-for="p in patientResults" :key="'pp-'+p.id" type="button" @click="onChangePatient(p)"
                                                class="block w-full truncate px-2.5 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                                            @{{ p.name }}
                                        </button>
                                    </div>
                                    <p v-else-if="patientQuery.length >= 2 && !patientSearching" class="mt-1 text-xs text-gray-400">Sin resultados.</p>
                                </div>
                            </div>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                              :class="viewAppointment.data.is_cancelled
                                        ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                        : (viewAppointment.data.is_completed
                                            ? 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300'
                                            : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300')">
                            @{{ getStageName(viewAppointment.data.lead_pipeline_stage_id) || 'Agendada' }}
                        </span>
                    </div>

                    <!-- Datos de la cita, en filas legibles -->
                    <div class="flex flex-col divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        <div class="flex items-center gap-3 px-3 py-2.5">
                            <span class="icon-user w-5 shrink-0 text-lg text-brandColor"></span>
                            <span class="w-24 shrink-0 text-xs font-medium uppercase text-gray-400">Sucursales</span>
                            <span class="text-sm font-medium text-gray-800 dark:text-gray-100">@{{ appointmentDoctorNames(viewAppointment.data) }}</span>
                        </div>
                        <div class="flex items-center gap-3 px-3 py-2.5">
                            <span class="icon-calendar w-5 shrink-0 text-lg text-brandColor"></span>
                            <span class="w-24 shrink-0 text-xs font-medium uppercase text-gray-400">Fecha</span>
                            <span class="text-sm text-gray-800 dark:text-gray-100">@{{ formatDate(new Date(viewAppointment.data.start)) }}</span>
                        </div>
                        <div class="flex items-center gap-3 px-3 py-2.5">
                            <span class="icon-calendar w-5 shrink-0 text-lg text-brandColor"></span>
                            <span class="w-24 shrink-0 text-xs font-medium uppercase text-gray-400">Horario</span>
                            <span class="text-sm text-gray-800 dark:text-gray-100">
                                @{{ formatTime(viewAppointment.data.start) }} – @{{ formatTime(viewAppointment.data.end) }}
                                <span class="ml-1 text-xs text-gray-400">(@{{ getDuration(viewAppointment.data.start, viewAppointment.data.end) }})</span>
                            </span>
                        </div>
                        <div class="flex items-center gap-3 px-3 py-2.5">
                            <span class="icon-product w-5 shrink-0 text-lg text-brandColor"></span>
                            <span class="w-24 shrink-0 text-xs font-medium uppercase text-gray-400">Servicio</span>
                            <span class="text-sm font-medium text-gray-800 dark:text-gray-100">
                                @{{ viewAppointment.data.product_name || (viewAppointment.data.product ? viewAppointment.data.product.name : '-') }}
                            </span>
                        </div>
                    </div>

                    <!-- Seguro médico del paciente (ver + agregar/editar) -->
                    <div v-if="viewAppointment.data.person_id">
                        <div class="mb-1.5 flex items-center justify-between">
                            <p class="text-xs font-medium uppercase text-gray-400">Seguro médico</p>
                            <button type="button" v-if="!apptInsurance.editing" @click="startEditApptInsurance"
                                    class="text-xs text-blue-600 hover:underline dark:text-blue-400">
                                @{{ apptInsurance.seguros.length ? 'Editar' : 'Agregar' }}
                            </button>
                        </div>

                        <!-- Vista: una fila por aseguradora, cada una con su propio estado -->
                        <div v-if="!apptInsurance.editing"
                             class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800">
                            <span v-if="apptInsurance.loading" class="text-xs text-gray-400">Cargando…</span>
                            <template v-else>
                                <span v-if="!apptInsurance.seguros.length" class="text-gray-400">Sin seguro registrado.</span>

                                <div v-else class="flex flex-col gap-1.5">
                                    <div v-for="s in apptInsurance.seguros" :key="'dv-seg-'+s.id"
                                         class="flex items-center justify-between gap-2">
                                        <span class="text-gray-700 dark:text-gray-300">@{{ s.name }}</span>
                                        <span v-if="apptInsuranceStatusOf(s)"
                                              class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                              :class="insuranceStatusBadge(apptInsuranceStatusOf(s))">@{{ apptInsuranceStatusOf(s) }}</span>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Editor: agregar/editar seguros -->
                        <div v-else class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="flex flex-col gap-2">
                                <div class="flex flex-col gap-1">
                                    <x-admin::form.control-group.label>CI del paciente</x-admin::form.control-group.label>
                                    <input type="text" v-model="apptInsurance.ci" placeholder="Cédula de identidad"
                                           class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400" />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <x-admin::form.control-group.label>Seguros del paciente</x-admin::form.control-group.label>
                                    <p class="text-xs text-gray-400">Marca todos los que correspondan; se verifica cada uno.</p>

                                    <div class="flex max-h-36 flex-col gap-0.5 overflow-y-auto rounded border border-gray-200 p-1.5 dark:border-gray-800">
                                        <label v-for="opt in insuranceOptions" :key="'dv-ins-'+opt.id"
                                               class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm text-gray-800 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                                            <input type="checkbox" :value="opt.id" v-model="apptInsurance.seguro_ids"
                                                   class="h-3.5 w-3.5 cursor-pointer rounded border-gray-300" />
                                            @{{ opt.name }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2 flex items-center gap-2">
                                <button type="button"
                                        class="text-xs px-3 py-1.5 rounded border dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center gap-1"
                                        @click="saveApptInsurance"
                                        :disabled="apptInsurance.saving || !apptInsurance.ci || !apptInsurance.seguro_ids.length">
                                    <x-admin::spinner v-if="apptInsurance.saving" class="w-3 h-3" />
                                    <span>Guardar y verificar</span>
                                </button>
                                <button type="button" class="text-xs px-3 py-1.5 rounded text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                                        :disabled="apptInsurance.saving" @click="cancelEditApptInsurance">Cancelar</button>
                            </div>

                            <!-- Resultado por aseguradora -->
                            <div v-if="apptInsuranceResults.length" class="mt-2 flex flex-col gap-1">
                                <span v-for="(r, i) in apptInsuranceResults" :key="'dv-res-'+i"
                                      class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                      :class="insuranceBadgeClass(r.badge)">
                                    <span v-if="r.seguro_name">@{{ r.seguro_name }} - </span>@{{ r.status }} - @{{ r.message }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Estado de la cita: selección única (pastel) -->
                    <div>
                        <p class="mb-1.5 text-xs font-medium uppercase text-gray-400">Estado de la cita</p>
                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                            <button type="button" :disabled="statusUpdating" @click="setAppointmentStatus('confirmed')"
                                    class="flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 disabled:opacity-50"
                                    :class="apptState() === 'confirmed'
                                        ? 'bg-blue-100 text-blue-800 ring-2 ring-blue-400 dark:bg-blue-900/50 dark:text-blue-200'
                                        : 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-900/20 dark:text-blue-300'">
                                <span class="icon-checkbox-select text-base"></span> Confirmada
                            </button>
                            <button type="button" :disabled="statusUpdating" @click="setAppointmentStatus('completed')"
                                    class="flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-400 disabled:opacity-50"
                                    :class="apptState() === 'completed'
                                        ? 'bg-green-100 text-green-800 ring-2 ring-green-400 dark:bg-green-900/50 dark:text-green-200'
                                        : 'bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-900/20 dark:text-green-300'">
                                <span class="icon-checkbox-select text-base"></span> Completada
                            </button>
                            <button type="button" :disabled="statusUpdating" @click="setAppointmentStatus('surgery')"
                                    class="flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 disabled:opacity-50"
                                    :class="apptState() === 'surgery'
                                        ? 'bg-amber-100 text-amber-800 ring-2 ring-amber-400 dark:bg-amber-900/50 dark:text-amber-200'
                                        : 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-900/20 dark:text-amber-300'">
                                <span class="icon-checkbox-select text-base"></span> Cirugía
                            </button>
                            <button type="button" :disabled="statusUpdating" @click="setAppointmentStatus('no_show')"
                                    class="flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 disabled:opacity-50"
                                    :class="apptState() === 'no_show'
                                        ? 'bg-orange-100 text-orange-800 ring-2 ring-orange-400 dark:bg-orange-900/50 dark:text-orange-200'
                                        : 'bg-orange-50 text-orange-700 hover:bg-orange-100 dark:bg-orange-900/20 dark:text-orange-300'">
                                <span class="icon-warning text-base"></span> Cita Perdida
                            </button>
                            <button type="button" :disabled="statusUpdating" @click="setAppointmentStatus('cancelled')"
                                    class="flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400 disabled:opacity-50"
                                    :class="apptState() === 'cancelled'
                                        ? 'bg-red-100 text-red-800 ring-2 ring-red-400 dark:bg-red-900/50 dark:text-red-200'
                                        : 'bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-300'">
                                <span class="icon-cross-large text-base"></span> Cancelada
                            </button>
                        </div>
                    </div>

                    <!-- Motivo (editable) -->
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <p class="text-xs font-medium uppercase text-gray-400">Motivo de consulta</p>
                            <button type="button" v-if="!editingReason" @click="startEditReason"
                                    class="text-xs text-blue-600 hover:underline dark:text-blue-400">Editar</button>
                        </div>

                        <div v-if="!editingReason"
                             class="whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                            @{{ viewAppointment.data.comment || 'Sin motivo registrado.' }}
                        </div>

                        <div v-else>
                            <textarea rows="3" v-model="reasonDraft" :disabled="reasonSaving"
                                      class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 disabled:opacity-50"
                                      placeholder="Motivo de consulta"></textarea>
                            <div class="mt-2 flex justify-end gap-2">
                                <button type="button" :disabled="reasonSaving" @click="editingReason = false"
                                        class="rounded px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100 disabled:opacity-50 dark:text-gray-300 dark:hover:bg-gray-800">Cancelar</button>
                                <button type="button" :disabled="reasonSaving" @click="saveReason"
                                        class="rounded bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                                    @{{ reasonSaving ? 'Guardando…' : 'Guardar motivo' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Historial de citas del paciente -->
                    <div v-if="viewAppointment.data.person_id">
                        <p class="mb-1.5 text-xs font-medium uppercase text-gray-400">
                            Historial de citas <span v-if="apptHistory.length" class="text-gray-300">(@{{ apptHistory.length }})</span>
                        </p>
                        <div v-if="apptHistoryLoading" class="py-3 text-center text-xs text-gray-400">Cargando…</div>
                        <div v-else-if="apptHistory.length" class="max-h-40 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                            <div v-for="a in apptHistory" :key="'h-'+a.id"
                                 class="flex items-center justify-between gap-2 px-2.5 py-1.5 text-xs"
                                 :class="a.id === viewAppointment.data.id ? 'bg-gray-50 dark:bg-gray-800' : ''">
                                <span class="text-gray-700 dark:text-gray-300">@{{ a.date }} · @{{ a.time }}</span>
                                <span class="truncate text-gray-500 dark:text-gray-400">@{{ a.doctor_name || '-' }}</span>
                                <span class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                      :class="a.state === 'cancelled' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                            : (a.state === 'completed' ? 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300'
                                            : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300')">
                                    @{{ a.state === 'cancelled' ? 'Cancelada' : (a.state === 'completed' ? 'Atendida' : 'Confirmada') }}
                                </span>
                            </div>
                        </div>
                        <p v-else class="rounded-lg border border-dashed border-gray-300 px-3 py-3 text-center text-xs text-gray-400 dark:border-gray-700">
                            Sin otras citas registradas.
                        </p>
                    </div>
                    </template>

                    <!-- Modo edición inline -->
                    <template v-else>
                        <div class="flex items-center justify-between border-b border-gray-200 pb-3 dark:border-gray-800">
                            <h3 class="text-base font-semibold dark:text-white">Editar cita</h3>
                            <span class="truncate text-sm text-gray-500 dark:text-gray-400">@{{ appointmentForm.person.name }}</span>
                        </div>

                        <div class="flex flex-col gap-1">
                            <x-admin::form.control-group.label class="required">Paciente</x-admin::form.control-group.label>
                            <x-admin::lookup
                                ::src="personSearchUrl"
                                ::params="{ limit: 200 }"
                                name="appointment_person_edit"
                                placeholder="Buscar paciente…"
                                @on-selected="onSelectPatient"
                                ::value="{ id: appointmentForm.person.id, name: appointmentForm.person.name }"
                            />
                        </div>
                        <div class="flex flex-col gap-1">
                            <x-admin::form.control-group.label>Sucursales</x-admin::form.control-group.label>
                            <v-doctor-multiselect :items="doctors" v-model="appointmentForm.doctor_ids"></v-doctor-multiselect>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Servicio</x-admin::form.control-group.label>
                                <v-doctor-multiselect :items="appointmentForm.product_name" v-model="appointmentForm.product_id"></v-doctor-multiselect>
                                <!-- <x-admin::lookup
                                    ::src="productSearchUrl"
                                    ::params="{ limit: 200 }"
                                    name="appointment_product_edit"
                                    placeholder="Buscar servicio…"
                                    @on-selected="onSelectService"
                                    ::value="{ id: appointmentForm.product_id, name: appointmentForm.product_name }"
                                /> -->
                            </div>
                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Fecha</x-admin::form.control-group.label>
                                <input
                                    type="date"
                                    class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                    v-model="appointmentForm.date"
                                />
                            </div>
                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Hora de inicio</x-admin::form.control-group.label>
                                <v-time-picker v-model="appointmentForm.startTime" @update:modelValue="syncAppointmentEndTime"></v-time-picker>
                            </div>
                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Duración</x-admin::form.control-group.label>
                                <select class="custom-select w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model.number="appointmentForm.duration" @change="syncAppointmentEndTime">
                                    <option :value="15">15 min</option>
                                    <option :value="30">30 min</option>
                                    <option :value="45">45 min</option>
                                    <option :value="60">60 min</option>
                                    <option :value="90">90 min</option>
                                    <option :value="120">120 min</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-1">
                                <x-admin::form.control-group.label>Estado</x-admin::form.control-group.label>
                                <select class="custom-select w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="appointmentForm.lead_pipeline_stage_id">
                                    <option :value="null" disabled>Estado</option>
                                    <option v-for="stage in stages" :key="'ed-st-'+stage.id" :value="stage.id">@{{ stage.name }}</option>
                                </select>
                            </div>

                            <!-- Seguro médico del paciente (editable; persiste en la ficha del paciente) -->
                            <div class="col-span-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Seguro médico</p>
                                <div class="flex flex-col gap-2">
                                    <div class="flex flex-col gap-1">
                                        <x-admin::form.control-group.label>CI del paciente</x-admin::form.control-group.label>
                                        <input type="text" v-model="apptInsurance.ci" placeholder="Cédula de identidad"
                                               class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400" />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <x-admin::form.control-group.label>Seguros del paciente</x-admin::form.control-group.label>
                                        <p class="text-xs text-gray-400">Marca todos los que correspondan; se verifica cada uno.</p>

                                        <div class="flex max-h-36 flex-col gap-0.5 overflow-y-auto rounded border border-gray-200 p-1.5 dark:border-gray-800">
                                            <label v-for="opt in insuranceOptions" :key="'ed-ins-'+opt.id"
                                                   class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm text-gray-800 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                                                <input type="checkbox" :value="opt.id" v-model="apptInsurance.seguro_ids"
                                                       class="h-3.5 w-3.5 cursor-pointer rounded border-gray-300" />
                                                @{{ opt.name }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-2 flex items-center gap-2">
                                    <button type="button"
                                            class="text-xs px-3 py-1.5 rounded border dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center gap-1"
                                            @click="saveApptInsurance"
                                            :disabled="apptInsurance.saving || !apptInsurance.ci || !apptInsurance.seguro_ids.length">
                                        <x-admin::spinner v-if="apptInsurance.saving" class="w-3 h-3" />
                                        <span>Guardar y verificar seguros</span>
                                    </button>
                                </div>

                                <!-- Resultado por aseguradora -->
                                <div v-if="apptInsuranceResults.length" class="mt-2 flex flex-col gap-1">
                                    <span v-for="(r, i) in apptInsuranceResults" :key="'ed-res-'+i"
                                          class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                          :class="insuranceBadgeClass(r.badge)">
                                        <span v-if="r.seguro_name">@{{ r.seguro_name }} - </span>@{{ r.status }} - @{{ r.message }}
                                    </span>
                                </div>
                            </div>
                            <div class="col-span-2 flex flex-col gap-1">
                                <x-admin::form.control-group.label>Motivo de consulta</x-admin::form.control-group.label>
                                <textarea rows="3" class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:focus:border-gray-400" v-model="appointmentForm.reason" placeholder="Motivo de consulta (opcional)"></textarea>
                            </div>
                        </div>

                        <p v-if="modalError" class="text-sm text-red-600 dark:text-red-400">@{{ modalError }}</p>
                    </template>
                  </div>
                  <!-- /Columna izquierda -->

                  <!-- Columna derecha: chat de WhatsApp del paciente -->
                  <div class="flex min-h-0 flex-col lg:w-1/2 lg:min-w-0 lg:border-l lg:border-gray-200 lg:pl-5 dark:lg:border-gray-800">
                      <p class="mb-2 flex items-center gap-1.5 text-xs font-medium uppercase text-gray-400">
                          <span class="icon-call text-sm text-green-600"></span> Chat de WhatsApp
                      </p>
                      <div v-if="viewAppointment.data.person_id" class="h-[440px] lg:h-auto lg:flex-1 lg:min-h-0">
                          <v-whatsapp-inbox
                              :key="'appt-chat-'+viewAppointment.data.person_id"
                              :context="{ conversationId: null, personId: viewAppointment.data.person_id, leadId: viewAppointment.data.lead_id, name: viewAppointment.data.person_name, phone: viewAppointment.data.person_whatsapp }"
                              :use-mock="false"
                              :full-height="true"
                              :hide-profile="true"
                              :thread-url="waThreadUrl"
                              :send-url="waSendUrl"
                              :send-media-url="waSendMediaUrl"
                              :templates-url="waTemplatesUrl"
                              :send-template-url="waSendTemplateUrl"
                              :agent-url-base="waAgentUrlBase"
                              :quick-replies-url="waQuickRepliesUrl"
                              :person-url-base="waPersonUrlBase"
                              :products-url="waProductsUrl"
                          ></v-whatsapp-inbox>
                      </div>
                      <div v-else class="flex h-[440px] flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-gray-300 text-center text-sm text-gray-400 dark:border-gray-700">
                          <span class="icon-call text-3xl"></span>
                          El paciente no está vinculado a un contacto del CRM.
                      </div>
                  </div>
                </div>
            </x-slot>

            <x-slot:footer>
                <!-- Footer en modo edición -->
                <div v-if="viewAppointment.data && editMode" class="flex w-full items-center justify-end gap-2">
                    <button type="button" class="secondary-button" :disabled="modalSaving" @click="cancelInlineEdit">Cancelar</button>
                    <button type="button" class="primary-button" :disabled="modalSaving" @click="saveAppointment">
                        <span v-if="!modalSaving">Guardar cambios</span>
                        <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando…</span>
                    </button>
                </div>

                <!-- Footer en modo detalle -->
                <div v-else-if="viewAppointment.data" class="flex w-full flex-wrap items-center justify-between gap-3">
                    <!-- Grupo 1: acciones sobre la cita -->
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- WhatsApp (recordatorio por gateway) -->
                        <div class="ddc-wa-wrap">
                            <div v-if="whatsappMenuOpen" class="ddc-wa-overlay" @click="closeWhatsappMenu"></div>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-md border border-green-600 px-3 py-1.5 text-sm font-medium text-green-700 transition hover:bg-green-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-600 disabled:cursor-not-allowed disabled:opacity-50 dark:text-green-400 dark:hover:bg-green-900/20"
                                :disabled="!viewAppointment.data.person_whatsapp || reminderSending"
                                :title="viewAppointment.data.person_whatsapp ? 'Enviar recordatorio por WhatsApp' : 'El paciente no tiene teléfono registrado'"
                                @click="toggleWhatsappMenu"
                            >
                                <span class="icon-call text-base"></span>
                                <span>WhatsApp</span>
                                <span class="icon-down-arrow text-sm"></span>
                            </button>
                            <div v-if="whatsappMenuOpen" class="ddc-wa-menu" @click.stop>
                                <button v-for="tpl in whatsappTemplates" :key="'wa-'+tpl.key" type="button" class="ddc-wa-menu-item" @click="openWhatsapp(tpl)">
                                    @{{ tpl.label }}
                                </button>
                            </div>
                        </div>

                    </div>

                    <!-- Grupo 2: navegación / edición -->
                    <div class="flex items-center gap-2">
                        <button type="button" class="secondary-button" v-if="viewAppointment.data.lead_id" @click="goToLead(viewAppointment.data.lead_id)">
                            Ver lead
                        </button>
                        <button type="button" class="secondary-button" @click="$refs.viewModal.close()">Cerrar</button>
                        <button type="button" class="primary-button" @click="editFromView">Editar</button>
                    </div>
                </div>
            </x-slot>
        </x-admin::modal>

        <x-admin::modal ref="groupModal">
            <x-slot:header>
                <div class="text-lg font-semibold dark:text-white">
                    Añadir cita de grupo
                    <div class="text-sm text-gray-500 dark:text-gray-300">
                        @{{ modalContext.dayLabel }} · @{{ modalContext.timeText }}
                    </div>
                </div>

            </x-slot>

            <x-slot:content>
                <div class="flex flex-col gap-3">

                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Título</div>
                            <input
                                type="text"
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                v-model="groupForm.title"
                                placeholder="Título"
                            />
                        </div>

                        <div class="flex flex-col gap-1">
                            <div class="text-xs text-gray-600 dark:text-gray-300">Tipo</div>
                            <select
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                v-model="groupForm.type"
                            >
                                <option value="meeting">Consulta</option>
                                <option value="call">Llamada</option>
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

        <Teleport to="body">
        <x-admin::modal ref="newPatientModal" size="normal">
            <x-slot:header>
                Nuevo paciente
            </x-slot:header>

            <x-slot:content>
                <div class="flex flex-col gap-4">

                    {{-- Buscar en SMD --}}
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 flex flex-col gap-2">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Buscar paciente en SMD</div>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                class="flex-1 rounded border px-3 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                                v-model="newPatient.smdQuery"
                                placeholder="Nombre o CI..."
                                @keyup.enter="searchSmd"
                            />
                            <button
                                type="button"
                                class="px-3 py-1.5 rounded border dark:border-gray-700 text-sm hover:bg-white dark:hover:bg-gray-700 flex items-center gap-1 whitespace-nowrap"
                                @click="searchSmd"
                                :disabled="newPatient.smdSearching"
                            >
                                <x-admin::spinner v-if="newPatient.smdSearching" class="w-3 h-3" />
                                <span v-else>🔍</span>
                                Buscar
                            </button>
                        </div>
                        <div
                            v-if="newPatient.smdResult"
                            class="text-xs px-2 py-1 rounded"
                            :class="newPatient.smdResult.found ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400'"
                        >
                            @{{ newPatient.smdResult.message }}
                        </div>
                    </div>

                    {{-- Nombre --}}
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Nombre <span class="text-red-500">*</span></label>
                        <input
                            type="text"
                            class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                            v-model="newPatient.name"
                            placeholder="Nombre completo"
                        />
                    </div>

                    {{-- Teléfono + Email --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Teléfono</label>
                            <input
                                type="text"
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                v-model="newPatient.phone"
                                placeholder="Ej: 0991234567"
                            />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Email</label>
                            <input
                                type="email"
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                v-model="newPatient.email"
                                placeholder="correo@ejemplo.com"
                            />
                        </div>
                    </div>

                    {{-- Fecha nacimiento + CI --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Fecha de nacimiento</label>
                            <input
                                type="date"
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                v-model="newPatient.fechaNacimiento"
                            />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-300">CI / Cédula</label>
                            <input
                                type="text"
                                class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                                v-model="newPatient.ci"
                                placeholder="Cédula de identidad"
                            />
                        </div>
                    </div>

                    {{-- Seguro médico --}}
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium text-gray-600 dark:text-gray-300">Seguro médico</label>
                        <select
                            class="w-full rounded border border-gray-200 px-2.5 py-2 text-sm text-gray-800 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                            v-model="newPatient.seguro_id"
                        >
                            <option :value="null">Sin seguro / No seleccionado</option>
                            <option v-for="opt in insuranceOptions" :key="'np-ins-'+opt.id" :value="opt.id">@{{ opt.name }}</option>
                        </select>
                    </div>

                    {{-- Verificar cobertura --}}
                    <div class="flex items-center gap-3">
                        <div class="relative group">
                            <button
                                type="button"
                                class="text-sm px-3 py-2 rounded border dark:border-gray-700 flex items-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-50 dark:hover:bg-gray-800"
                                @click="verifyInsuranceNewPatient"
                                :disabled="newPatient.insuranceVerifying || !newPatientCanVerifyInsurance"
                            >
                                <x-admin::spinner v-if="newPatient.insuranceVerifying" class="w-3.5 h-3.5" />
                                <span>Verificar cobertura</span>
                            </button>
                            <div
                                v-if="newPatientVerifyTooltip && !newPatientCanVerifyInsurance"
                                class="absolute bottom-full left-0 mb-1 px-2 py-1 rounded bg-gray-800 text-white text-xs whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none z-10"
                            >
                                @{{ newPatientVerifyTooltip }}
                            </div>
                        </div>
                        <span
                            v-if="newPatient.insuranceResult"
                            class="text-xs px-3 py-1.5 rounded-full font-medium"
                            :class="insuranceBadgeClass(newPatient.insuranceResult.badge)"
                        >
                            @{{ newPatient.insuranceResult.status }} - @{{ newPatient.insuranceResult.message }}
                        </span>
                    </div>

                    {{-- Error --}}
                    <div v-if="newPatient.error" class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded px-3 py-2">
                        @{{ newPatient.error }}
                    </div>

                </div>
            </x-slot:content>

            <x-slot:footer>
                <div class="flex justify-between items-center w-full">
                    <button
                        type="button"
                        class="secondary-button"
                        @click="closeNewPatientModal"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="primary-button flex items-center gap-2"
                        @click="saveNewPatient"
                        :disabled="newPatient.saving || !newPatient.name.trim()"
                    >
                        <x-admin::spinner v-if="newPatient.saving" class="w-3.5 h-3.5" />
                        Crear paciente
                    </button>
                </div>
            </x-slot:footer>
        </x-admin::modal>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="hoveredEvent"
                class="fixed z-[9999] w-64 rounded-lg border border-gray-200 bg-white dark:bg-gray-900 dark:border-gray-700 shadow-xl p-3 text-xs pointer-events-none"
                :style="{ top: tooltipPos.top + 'px', left: tooltipPos.left + 'px' }"
                style="min-width:220px"
            >
                <div class="font-semibold text-gray-800 dark:text-gray-100 mb-1 truncate">@{{ hoveredEvent.person_name || hoveredEvent.title || hoveredEvent.type }}</div>
                <div class="text-gray-500 dark:text-gray-400 mb-1" v-if="hoveredEvent.doctor_name">Dr. @{{ hoveredEvent.doctor_name }}</div>
                <div class="text-gray-500 dark:text-gray-400 mb-1">@{{ formatTime(hoveredEvent.start) }} – @{{ formatTime(hoveredEvent.end) }}</div>
                <div class="text-gray-500 dark:text-gray-400 mb-1" v-if="getStageName(hoveredEvent.lead_pipeline_stage_id)">
                    Estado: @{{ getStageName(hoveredEvent.lead_pipeline_stage_id) }}
                </div>
                <div class="text-gray-600 dark:text-gray-300 italic truncate" v-if="hoveredEvent.comment">@{{ hoveredEvent.comment }}</div>
                <div class="mt-2 text-blue-600 dark:text-blue-400 font-medium" v-if="hoveredEvent.lead_id">→ Ver lead</div>
            </div>
        </Teleport>
    </div>
</script>

<script type="module">
    // Persistencia de filtros del calendario (vista, fecha y rango) por cookie.
    const DDC_PREFS_COOKIE = 'ddc_calendar_prefs';

    function ddcSetCookie(name, value, days = 365) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = `${name}=${encodeURIComponent(value)}; expires=${expires}; path=/; SameSite=Lax`;
    }

    function ddcGetCookie(name) {
        return document.cookie.split('; ').reduce((acc, cookie) => {
            const [key, ...rest] = cookie.split('=');
            return key === name ? decodeURIComponent(rest.join('=')) : acc;
        }, '');
    }

    function ddcLoadPrefs() {
        try {
            const raw = ddcGetCookie(DDC_PREFS_COOKIE);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

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
            // Autosave del formulario de cita NUEVA: si el usuario cierra por
            // accidente (Esc/click-afuera), al reabrir recupera lo que escribió.
            appointmentForm: {
                deep: true,
                handler(val) {
                    if (val.id || this.editMode) return; // solo citas nuevas, no edición
                    try { localStorage.setItem('ddc_appt_draft', JSON.stringify(val)); } catch (e) {}
                },
            },
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
            const rangeEndDefault = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 30);

            // Preferencias persistidas (cookie): vista, fecha ancla y rango.
            const prefs = ddcLoadPrefs();

            return {
                isLoading: false,
                isSaving: false,
                addError: '',
                hourHeight: 84,
                startISO: prefs.startISO || this.toISO(start),
                rangeStart: prefs.rangeStart || this.toISO(start),
                rangeEnd: prefs.rangeEnd || this.toISO(rangeEndDefault),
                days: [],
                doctors: [],
                appointments: [],
                availability: [],
                stages: [],
                selectedDoctorIds: [],
                doctorFilterInitialized: false,
                patientSearch: '',
                patientSearchTimer: null,
                nowMinutes: 0,
                nowText: '',
                nowTimer: null,
                datePickerOpen: false,
                datePickerMonthISO: '',
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
                    lead_id: null,
                    person: {
                        id: '',
                        name: '',
                    },
                    doctor_id: null,
                    doctor_ids: [],
                    product_id: null,
                    product_name: '',
                    date: null,
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
                versionEndpoint: "{{ route('admin.activities.version') }}",
                // Auto-actualización por polling liviano: solo mientras el calendario
                // está montado y la pestaña visible. lastVersion = firma del rango.
                pollTimer: null,
                pollIntervalMs: 10000,
                lastVersion: null,
                storeUrl: "{{ route('admin.activities.store') }}",
                appointmentStoreUrl: "{{ route('admin.activities.appointments.store') }}",
                personSearchUrl: "{{ route('admin.contacts.persons.search') }}",
                productSearchUrl: "{{ route('admin.products.search') }}",
                reminderSendUrl: "{{ route('admin.whatsapp.reminders.send') }}",
                reminderSending: false,
                appointmentStatusUrlTemplate: "{{ route('admin.activities.appointments.status', 'replaceId') }}",
                appointmentUpdateUrlTemplate: "{{ route('admin.activities.appointments.update', 'replaceId') }}",
                statusUpdating: false,
                // Edición del motivo de consulta dentro del modal de detalle.
                editingReason: false,
                reasonDraft: '',
                reasonSaving: false,
                // Cambio de paciente dentro del modal de detalle.
                editingPatient: false,
                patientQuery: '',
                patientResults: [],
                patientSearching: false,
                // WhatsApp: chat embebido del paciente en el modal de detalle
                waThreadUrl: "{{ route('admin.whatsapp.thread') }}",
                waSendUrl: "{{ route('admin.whatsapp.send') }}",
                waSendMediaUrl: "{{ route('admin.whatsapp.send-media') }}",
                waTemplatesUrl: "{{ route('admin.whatsapp.templates') }}",
                waSendTemplateUrl: "{{ route('admin.whatsapp.send-template') }}",
                waAgentUrlBase: "{{ url(config('app.admin_path').'/whatsapp/conversations') }}",
                waQuickRepliesUrl: "{{ route('admin.whatsapp.quick-replies.index') }}",
                waPersonUrlBase: "{{ url(config('app.admin_path').'/whatsapp/persons') }}",
                waProductsUrl: "{{ route('admin.whatsapp.products') }}",
                apptHistoryUrlTemplate: "{{ route('admin.activities.persons.appointments', 'replaceId') }}",
                apptHistory: [],
                apptHistoryLoading: false,
                editMode: false,
                editUrlTemplate: "{{ route('admin.activities.edit', 'replaceId') }}",
                deleteUrlTemplate: "{{ route('admin.activities.delete', 'replaceId') }}",
                leadUrlTemplate: "{{ route('admin.leads.view', 'replaceId') }}",
                whatsappMenuOpen: false,
                // Plantillas del boton WhatsApp del detalle de cita. Los
                // placeholders :fecha, :hora y :doctor los sustituye
                // buildWhatsappMessage() con los datos de la cita abierta.
                clinicAddress: @json(config('whatsapp.clinic.address') ?: 'Av Roca y  Coronado 3er anillo interno entrando por  el surtidor El Arroyo Calle Burapacú Nro 2888,  lado Taller  Bavaria'),
                surveyUrl: @json(config('whatsapp.clinic.survey_url') ?: 'https://forms.gle/z6zbPMPzPYU1knKZ9'),
                whatsappTemplates: [
                    {
                        key: 'appointment_reminder',
                        label: 'Recordatorio de cita',
                        body: 'Buenos días! \nRecordarle que tiene cita el :fecha a horas :hora con el Dr(a). :doctor en la clínica Odontoking :direccion!\nEs obligatorio confirmar su asistencia para evitar disponer de su espacio! Gracias!!\n\nResponda *SÍ* para confirmar o *NO* para cancelar su cita.',
                    },
                    {
                        key: 'appointment_confirmation',
                        label: 'Confirmación de Cita',
                        body: 'Buenos días! \nSu cita ha sido agendada para el :fecha a horas :hora con el Dr(a) :doctor en la clínica Odontoking \n:direccion! Gracias!!',
                    },
                    {
                        key: 'survey',
                        label: 'Encuesta',
                        body: 'Muy estimado paciente de ODONTOKING, comprometidos con la mejora constante queremos que nos colaboré con su opinión en la siguiente encuesta, muchas gracias de antemano.\n:encuesta',
                    },
                    {
                        key: 'first_appointment_reminder',
                        label: 'Recordatorio Primera Cita',
                        body: 'Buenos días! \nRecordarle que tiene cita el :fecha a horas :hora con el Dr(a). :doctor en la clínica Odontoking :direccion! Es obligatorio confirmar su asistencia para evitar disponer de su espacio! \nPor ser su primera cita por favor se le pide estar 15min  antes de la hora agendada para poder realizar su historia clínica de inicio.\nGracias!!\n\nResponda *SÍ* para confirmar o *NO* para cancelar su cita.',
                    },
                ],
                hoveredEventId: null,
                hoveredEvent: null,
                tooltipPos: { top: 0, left: 0 },
                scheduleStoreUrl: "{{ route('admin.schedules.store') }}",
                insuranceOptionsUrl: "{{ route('admin.contacts.persons.insurance_options') }}",
                verifyInsuranceQuickUrl: "{{ route('admin.contacts.persons.verify_insurance_quick') }}",
                insuranceStatusUrlTemplate: "{{ route('admin.contacts.persons.insurance_status', 'replaceId') }}",
                insuranceUpdateUrlTemplate: "{{ route('admin.contacts.persons.update_insurance', 'replaceId') }}",
                personStoreUrl: "{{ route('admin.contacts.persons.store') }}",
                searchSmdUrl: "{{ route('admin.contacts.persons.search_smd') }}",
                syncSmdBaseUrl: "{{ route('admin.contacts.persons.sync_smd', ['id' => '__ID__']) }}",
                viewType: ['day', 'week', 'month', 'list'].includes(prefs.viewType) ? prefs.viewType : 'day',
                insuranceOptions: [],
                insurance: {
                    ci: '',
                    seguro_id: null,
                    verifying: false,
                    result: null,
                },
                // Seguro del paciente de la cita abierta (modal detalle/edición).
                // Independiente de `insurance` (modal Nueva cita) para no pisarse.
                apptInsurance: {
                    loading: false,
                    saving: false,
                    editing: false,
                    ci: '',
                    // El paciente puede tener varias aseguradoras: se guardan y verifican todas.
                    seguro_ids: [],
                    seguros: [],
                    status: null,
                    result: null,
                },
                newPatient: {
                    name: '',
                    phone: '',
                    email: '',
                    fechaNacimiento: '',
                    ci: '',
                    seguro_id: null,
                    insuranceResult: null,
                    insuranceVerifying: false,
                    smdQuery: '',
                    smdResult: null,
                    smdSearching: false,
                    saving: false,
                    error: '',
                },
            };
        },
        computed: {
            /**
             * Detalle de la última verificación, una entrada por aseguradora.
             * `results` llega cuando el backend verificó varias; si no, el resultado
             * suelto es la entrada única (p. ej. un error de red).
             */
            apptInsuranceResults() {
                const r = this.apptInsurance.result;
                if (! r) return [];

                return Array.isArray(r.results) && r.results.length ? r.results : [r];
            },

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
                if (this.viewType === 'list') {
                    return `${this.rangeStart || '-'} → ${this.rangeEnd || '-'}`;
                }
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
            listAppointments() {
                if (!this.selectedDoctorIds.length) return [];
                const ids = new Set(this.selectedDoctorIds.map(id => String(id)));
                return this.appointments
                    .filter(a => ids.has(String(a.doctor_id)))
                    .slice()
                    .sort((a, b) => new Date(a.start) - new Date(b.start));
            },
            listGroups() {
                const groups = [];
                const map = {};
                for (const ev of this.listAppointments) {
                    const date = String(ev.start).slice(0, 10);
                    if (!map[date]) {
                        map[date] = {
                            date,
                            label: this.formatDate(this.parseISODate(date)),
                            items: []
                        };
                        groups.push(map[date]);
                    }
                    map[date].items.push(ev);
                }
                return groups;
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
            newPatientCanVerifyInsurance() {
                if (!this.newPatient.ci || !this.newPatient.seguro_id) return false;
                const opt = this.insuranceOptions.find(o => o.id === this.newPatient.seguro_id);
                if (opt && /no\s*tiene/i.test(opt.name)) return false;
                return true;
            },
            newPatientVerifyTooltip() {
                if (!this.newPatient.ci) return 'Ingresa la cédula de identidad';
                if (!this.newPatient.seguro_id) return 'Selecciona un tipo de seguro';
                const opt = this.insuranceOptions.find(o => o.id === this.newPatient.seguro_id);
                if (opt && /no\s*tiene/i.test(opt.name)) return 'Sin seguro seleccionado';
                return '';
            },
        },
        mounted() {
            this.fetch();
            this.loadInsuranceOptions();
            this.updateNow();
            this.nowTimer = window.setInterval(this.updateNow, 30000);
            window.addEventListener('keydown', this.onKeyDown);

            // Recargar el calendario cuando termina una sincronización con ShareMeData.
            this.onSmdSynced = () => this.fetch();
            this.$emitter.on('smd-sync-completed', this.onSmdSynced);

            // Auto-actualización: polling liviano de la firma del rango. Corre solo
            // mientras esta vista está montada; se pausa con la pestaña oculta y
            // recarga al instante al volver.
            this.startPolling();
            document.addEventListener('visibilitychange', this.onVisibilityChange);
        },
        beforeUnmount() {
            if (this.nowTimer) {
                clearInterval(this.nowTimer);
                this.nowTimer = null;
            }
            window.removeEventListener('keydown', this.onKeyDown);

            if (this.onSmdSynced) {
                this.$emitter.off('smd-sync-completed', this.onSmdSynced);
            }

            this.stopPolling();
            document.removeEventListener('visibilitychange', this.onVisibilityChange);
        },
        methods: {
            loadInsuranceOptions() {
                this.$axios.get(this.insuranceOptionsUrl)
                    .then(r => { this.insuranceOptions = r.data.options || []; })
                    .catch(() => {});
            },
            verifyInsurance() {
                if (!this.insurance.ci || !this.insurance.seguro_id) return;
                this.insurance.verifying = true;
                this.insurance.result = null;
                this.$axios.post(this.verifyInsuranceQuickUrl, {
                    ci_paciente: this.insurance.ci,
                    seguro_paciente: this.insurance.seguro_id,
                })
                .then(r => { this.insurance.result = r.data; })
                .catch(e => {
                    this.insurance.result = e.response?.data || { status: 'ERROR', message: 'Error al verificar.', badge: 'error' };
                })
                .finally(() => { this.insurance.verifying = false; });
            },
            insuranceBadgeClass(badge) {
                if (badge === 'success') return 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300';
                if (badge === 'warning') return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300';
                return 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300';
            },

            // Color del estado de cobertura persistido (insurance_status del paciente).
            insuranceStatusBadge(status) {
                if (status === 'VIGENTE') return 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300';
                if (status === 'EN_MORA') return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300';
                return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
            },

            /**
             * Estado a mostrar junto a una aseguradora concreta.
             * Prioriza el resultado recién verificado de ESA aseguradora; si no hay,
             * usa el estado persistido de ESA aseguradora (person_insurance_statuses).
             * Nunca cae al agregado del paciente: atribuirle a un seguro el veredicto
             * de otro sería mostrar información falsa en recepción.
             */
            apptInsuranceStatusOf(seguro) {
                const match = this.apptInsuranceResults
                    .find(r => String(r.seguro_id) === String(seguro.id));

                return match ? match.status : (seguro.status || null);
            },

            // Carga el seguro guardado del paciente de la cita abierta.
            loadApptInsurance(personId) {
                this.apptInsurance.editing = false;
                this.apptInsurance.result = null;
                this.apptInsurance.ci = '';
                this.apptInsurance.seguro_ids = [];
                this.apptInsurance.seguros = [];
                this.apptInsurance.status = null;

                if (!personId) return;

                this.apptInsurance.loading = true;
                this.$axios.get(this.insuranceStatusUrlTemplate.replace('replaceId', personId))
                    .then(res => {
                        this.apptInsurance.ci = res.data?.ci || '';
                        this.apptInsurance.seguros = res.data?.seguros || [];
                        this.apptInsurance.seguro_ids = this.apptInsurance.seguros.map(s => s.id);
                        this.apptInsurance.status = res.data?.status || null;
                    })
                    .catch(() => {})
                    .finally(() => { this.apptInsurance.loading = false; });
            },

            startEditApptInsurance() {
                this.apptInsurance.result = null;
                this.apptInsurance.editing = true;
            },

            cancelEditApptInsurance() {
                this.apptInsurance.editing = false;
                this.apptInsurance.result = null;
                // Restaurar lo persistido (descarta lo tecleado sin guardar).
                this.loadApptInsurance(this.viewAppointment.data?.person_id);
            },

            // Persiste el seguro (CI + tipo) en la ficha del paciente y lo verifica.
            // Reutiliza el endpoint updateAndVerify de InsuranceController.
            saveApptInsurance() {
                const personId = this.viewAppointment.data?.person_id;
                if (!personId || this.apptInsurance.saving) return;
                if (!this.apptInsurance.ci || !this.apptInsurance.seguro_ids.length) return;

                this.apptInsurance.saving = true;
                this.apptInsurance.result = null;

                this.$axios.post(this.insuranceUpdateUrlTemplate.replace('replaceId', personId), {
                        ci_paciente: this.apptInsurance.ci,
                        seguro_paciente: this.apptInsurance.seguro_ids,
                    })
                    .then(res => {
                        this.apptInsurance.result = res.data;
                        this.apptInsurance.status = res.data?.status || this.apptInsurance.status;

                        /**
                         * Refleja los seguros recién guardados sin re-pedirlos al backend,
                         * pegándole a cada uno el estado que devolvió SU verificación.
                         */
                        const byId = new Map(
                            (res.data?.results ?? []).map(r => [String(r.seguro_id), r.status])
                        );

                        this.apptInsurance.seguros = this.apptInsurance.seguro_ids
                            .map(id => this.insuranceOptions.find(o => String(o.id) === String(id)))
                            .filter(Boolean)
                            .map(o => ({ id: o.id, name: o.name, status: byId.get(String(o.id)) || null }));

                        this.apptInsurance.editing = false;

                        const count = res.data?.results?.length ?? 1;
                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: `Seguro del paciente actualizado (${count} verificado(s)).`,
                        });
                    })
                    .catch(err => {
                        this.apptInsurance.result = err.response?.data
                            || { status: 'ERROR', message: 'No se pudo guardar el seguro.', badge: 'error' };
                    })
                    .finally(() => { this.apptInsurance.saving = false; });
            },
            closeNewPatientModal() {
                if (this.$refs.newPatientModal) this.$refs.newPatientModal.close();
            },
            async searchSmd() {
                if (!this.newPatient.smdQuery.trim()) return;
                this.newPatient.smdSearching = true;
                this.newPatient.smdResult = null;
                try {
                    const { data } = await this.$axios.get(this.searchSmdUrl, { params: { q: this.newPatient.smdQuery } });
                    if (data && data.found) {
                        if (data.name)     this.newPatient.name            = data.name;
                        if (data.phone)    this.newPatient.phone           = data.phone;
                        if (data.email)    this.newPatient.email           = data.email;
                        if (data.ci)       this.newPatient.ci              = data.ci;
                        if (data.birthday) this.newPatient.fechaNacimiento = data.birthday;
                        this.newPatient.smdResult = { found: true, message: 'Datos cargados desde SMD' };
                    } else {
                        this.newPatient.smdResult = { found: false, message: data.message || 'No encontrado en SMD' };
                    }
                } catch {
                    this.newPatient.smdResult = { found: false, message: 'Error al buscar en SMD' };
                } finally {
                    this.newPatient.smdSearching = false;
                }
            },
            async verifyInsuranceNewPatient() {
                this.newPatient.insuranceVerifying = true;
                this.newPatient.insuranceResult = null;
                try {
                    const { data } = await this.$axios.post(this.verifyInsuranceQuickUrl, {
                        ci_paciente: this.newPatient.ci,
                        seguro_paciente: this.newPatient.seguro_id,
                    });
                    this.newPatient.insuranceResult = data;
                } catch {
                    this.newPatient.insuranceResult = { badge: 'gray', status: 'ERROR', message: 'No se pudo verificar' };
                } finally {
                    this.newPatient.insuranceVerifying = false;
                }
            },
            async saveNewPatient() {
                if (!this.newPatient.name.trim()) return;
                this.newPatient.saving = true;
                this.newPatient.error = '';
                try {
                    const payload = { entity_type: 'persons', name: this.newPatient.name.trim() };
                    if (this.newPatient.phone)           payload.contact_numbers     = [{ label: 'work', value: this.newPatient.phone }];
                    if (this.newPatient.email)           payload.emails              = [{ label: 'work', value: this.newPatient.email }];
                    if (this.newPatient.fechaNacimiento) payload.fecha_nacimiento    = this.newPatient.fechaNacimiento;
                    if (this.newPatient.ci)              payload.ci_paciente         = this.newPatient.ci;
                    // seguro_paciente es multiselect: siempre se manda como lista.
                    if (this.newPatient.seguro_id)       payload.seguro_paciente     = [this.newPatient.seguro_id];
                    if (this.newPatient.insuranceResult?.status) {
                        payload.estado_seguro_paciente = this.newPatient.insuranceResult.status;
                    }
                    const { data } = await this.$axios.post(this.personStoreUrl, payload);
                    const person = data.data;
                    this.appointmentForm.person = { id: person.id, name: person.name };
                    if (person.ci_paciente) this.insurance.ci = person.ci_paciente;
                    if (person.id) {
                        const syncUrl = this.syncSmdBaseUrl.replace('__ID__', person.id);
                        this.$axios.post(syncUrl).catch(() => {});
                    }
                    this.closeNewPatientModal();
                } catch (e) {
                    this.newPatient.error = e?.response?.data?.message || 'Error al crear el paciente.';
                } finally {
                    this.newPatient.saving = false;
                }
            },
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
                if (!result?.id && result?.name) {
                    this.newPatient = {
                        name: result.name, phone: '', email: '',
                        fechaNacimiento: '', ci: '', seguro_id: null,
                        insuranceResult: null, insuranceVerifying: false,
                        smdQuery: result.name, smdResult: null, smdSearching: false,
                        saving: false, error: '',
                    };
                    this.$nextTick(() => { if (this.$refs.newPatientModal) this.$refs.newPatientModal.open(); });
                    return;
                }

                let phone = '';
                if (result?.contact_numbers && Array.isArray(result.contact_numbers) && result.contact_numbers.length > 0) {
                    phone = result.contact_numbers[0].value || '';
                }

                this.appointmentForm.person = {
                    id: result?.id || null,
                    name: result?.name || '',
                    phone: phone,
                };

                this.insurance.ci = result?.ci_paciente || '';
                this.insurance.seguro_id = null;
                this.insurance.result = null;

                if (result?.ci_paciente && this.insurance.seguro_id) {
                    this.verifyInsurance();
                }
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
                this.persistPrefs();
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
                this.persistPrefs();
                this.fetch();
            },
            prevPeriod() {
                const d = this.parseISODate(this.startISO);
                if (this.viewType === 'week') d.setDate(d.getDate() - 7);
                else if (this.viewType === 'month') d.setMonth(d.getMonth() - 1);
                else d.setDate(d.getDate() - 1);
                this.startISO = this.toISO(d);
                this.persistPrefs();
                this.fetch();
            },
            nextPeriod() {
                const d = this.parseISODate(this.startISO);
                if (this.viewType === 'week') d.setDate(d.getDate() + 7);
                else if (this.viewType === 'month') d.setMonth(d.getMonth() + 1);
                else d.setDate(d.getDate() + 1);
                this.startISO = this.toISO(d);
                this.persistPrefs();
                this.fetch();
            },
            goToday() {
                const today = new Date();
                this.startISO = this.toISO(today);
                this.persistPrefs();
                this.fetch();
            },
            applyRange() {
                if (this.viewType !== 'list') {
                    this.viewType = 'list';
                }
                this.persistPrefs();
                this.fetch();
            },
            persistPrefs() {
                ddcSetCookie(DDC_PREFS_COOKIE, JSON.stringify({
                    viewType: this.viewType,
                    startISO: this.startISO,
                    rangeStart: this.rangeStart,
                    rangeEnd: this.rangeEnd,
                }));
            },
            // Espera a que deje de escribir (400 ms) antes de consultar.
            onPatientSearch() {
                clearTimeout(this.patientSearchTimer);
                this.patientSearchTimer = setTimeout(() => this.fetch(), 400);
            },
            fetch() {
                this.isLoading = true;
                // Re-basear el polling al rango/estado recién cargado: evita una
                // recarga redundante tras navegar o editar.
                this.lastVersion = null;

                this.$axios.get(this.endpoint, {
                        params: {
                            view_type: 'calendar',
                            calendar_mode: 'doctor',
                            calendar_view: this.viewType,
                            start: this.startISO,
                            range_start: this.rangeStart,
                            range_end: this.rangeEnd,
                            q: this.patientSearch.trim() || undefined,
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
            startPolling() {
                this.stopPolling();
                this.pollTimer = window.setInterval(this.pollChanges, this.pollIntervalMs);
            },
            stopPolling() {
                if (this.pollTimer) {
                    clearInterval(this.pollTimer);
                    this.pollTimer = null;
                }
            },
            // Pestaña oculta: no gastar. Al volver, chequear al instante.
            onVisibilityChange() {
                if (document.hidden) {
                    this.stopPolling();
                } else {
                    this.pollChanges();
                    this.startPolling();
                }
            },
            // Consulta la firma barata del rango; solo recarga si cambió.
            pollChanges() {
                if (document.hidden) {
                    return;
                }

                this.$axios.get(this.versionEndpoint, {
                        params: {
                            calendar_view: this.viewType,
                            start: this.startISO,
                            range_start: this.rangeStart,
                            range_end: this.rangeEnd
                        }
                    })
                    .then(r => {
                        const v = r.data && r.data.version;
                        if (!v) return;
                        // Primera lectura = línea base (no recarga). Si después cambia,
                        // recarga la grilla completa.
                        if (this.lastVersion !== null && v !== this.lastVersion) {
                            this.fetch();
                        }
                        this.lastVersion = v;
                    })
                    .catch(() => {});
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
                const events = this.appointments
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
                            height,
                            _startMin: startMin,
                            _endMin: endMin
                        };
                    });

                return this.layoutOverlappingEvents(events);
            },
            /**
             * Asigna left/width a cada evento segun cuantas citas se solapan en su
             * mismo horario, para que una persona vea de un vistazo cuantas citas
             * reales hay en un slot (1 cita = 100% ancho, 2 = 50%/50%, etc.) en vez
             * de que una tape visualmente a la otra. Recordar: para que dos citas
             * coexistan en el mismo horario, la primera tuvo que cancelarse o el
             * sistema lo permite explicitamente (ver AppointmentService::process()).
             */
            layoutOverlappingEvents(events) {
                if (events.length < 2) {
                    return events.map(ev => ({ ...ev, left: 0, width: 100 }));
                }

                const sorted = [...events].sort((a, b) => a._startMin - b._startMin);

                // Agrupa eventos en "clusters" de solapamiento transitivo: si A se
                // solapa con B y B con C, los tres comparten el mismo ancho de columna
                // aunque A y C no se toquen directamente entre si.
                const clusters = [];
                let current = [];
                let clusterEnd = -Infinity;

                sorted.forEach(ev => {
                    if (current.length === 0 || ev._startMin < clusterEnd) {
                        current.push(ev);
                        clusterEnd = Math.max(clusterEnd, ev._endMin);
                    } else {
                        clusters.push(current);
                        current = [ev];
                        clusterEnd = ev._endMin;
                    }
                });
                if (current.length > 0) clusters.push(current);

                const positioned = [];

                clusters.forEach(cluster => {
                    if (cluster.length === 1) {
                        positioned.push({ ...cluster[0], left: 0, width: 100 });
                        return;
                    }

                    // Greedy column assignment: cada evento ocupa la primera columna
                    // donde no choca con lo ya asignado a esa columna.
                    const columnEnds = [];
                    const columnOf = [];

                    cluster.forEach(ev => {
                        let col = columnEnds.findIndex(end => ev._startMin >= end);
                        if (col === -1) {
                            col = columnEnds.length;
                            columnEnds.push(ev._endMin);
                        } else {
                            columnEnds[col] = ev._endMin;
                        }
                        columnOf.push(col);
                    });

                    const totalColumns = columnEnds.length;
                    const widthPct = 100 / totalColumns;

                    cluster.forEach((ev, idx) => {
                        positioned.push({
                            ...ev,
                            left: columnOf[idx] * widthPct,
                            width: widthPct
                        });
                    });
                });

                return positioned;
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
                // Capturar el borrador ANTES de resetear el form.
                let draft = null;
                try { draft = JSON.parse(localStorage.getItem('ddc_appt_draft') || 'null'); } catch (e) {}

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
                this.appointmentForm.lead_id = null;
                this.appointmentForm.doctor_id = this.quickMenu.doctorId;
                // Precargar el doctor del slot clickeado en el multiselect (como string).
                this.appointmentForm.doctor_ids = this.quickMenu.doctorId != null
                    ? [String(this.quickMenu.doctorId)]
                    : [];
                this.appointmentForm.startTime = this.quickMenu.startTime;
                this.appointmentForm.duration = this.appointmentForm.duration || 60;
                this.syncAppointmentEndTime();
                this.appointmentForm.reason = '';
                const confirmedStage = this.stages.find(s => /confirm/i.test(s.name));
                this.appointmentForm.lead_pipeline_stage_id = confirmedStage?.id ?? this.stages[0]?.id ?? null;
                this.appointmentForm.person = {
                    id: '',
                    name: ''
                };
                this.appointmentForm.product_id = null;
                this.appointmentForm.product_name = '';

                // Restaurar lo que el usuario había escrito (paciente, servicio,
                // motivo, etc.), respetando el doctor/día/hora del slot clickeado.
                if (draft && !draft.id) {
                    if (draft.person && draft.person.name) this.appointmentForm.person = draft.person;
                    this.appointmentForm.product_id = draft.product_id || null;
                    this.appointmentForm.product_name = draft.product_name || '';
                    this.appointmentForm.reason = draft.reason || '';
                    if (draft.duration) this.appointmentForm.duration = draft.duration;
                    if (draft.lead_pipeline_stage_id) this.appointmentForm.lead_pipeline_stage_id = draft.lead_pipeline_stage_id;
                    this.syncAppointmentEndTime();
                }

                this.closeQuickMenu();
                this.$refs.appointmentModal.open();
            },
            // Fill the shared appointmentForm from an existing appointment.
            populateAppointmentForm(ev) {
                this.modalError = '';
                this.modalSaving = false;

                const start = new Date(ev.start);
                const end = new Date(ev.end);
                const isoDate = this.toISO(start);
                const dayLabel = this.days.find(d => d.date === isoDate)?.label || isoDate;

                this.modalContext = {
                    doctorId: ev.doctor_id,
                    doctorLabel: ev.doctor_name,
                    dayISO: isoDate,
                    dayLabel: dayLabel,
                    timeText: this.formatTime(ev.start),
                };

                this.appointmentForm.id = ev.id;
                this.appointmentForm.date = isoDate;
                this.appointmentForm.doctor_id = ev.doctor_id;
                // Precargar TODOS los doctores de la cita en el multiselect (strings).
                this.appointmentForm.doctor_ids = Array.isArray(ev.doctor_ids) && ev.doctor_ids.length
                    ? ev.doctor_ids.map(id => String(id))
                    : (ev.doctor_id != null ? [String(ev.doctor_id)] : []);
                this.appointmentForm.startTime = `${this.pad2(start.getHours())}:${this.pad2(start.getMinutes())}`;
                this.appointmentForm.endTime = `${this.pad2(end.getHours())}:${this.pad2(end.getMinutes())}`;
                this.syncAppointmentDurationFromTimes();
                this.appointmentForm.reason = ev.comment || '';
                this.appointmentForm.lead_id = ev.lead_id || null;
                this.appointmentForm.lead_pipeline_stage_id = ev.lead_pipeline_stage_id;
                this.appointmentForm.person = { id: ev.person_id || null, name: ev.person_name || '' };
                this.appointmentForm.product_id = ev.product_id || (ev.product ? ev.product.id : null);
                this.appointmentForm.product_name = ev.product_name || (ev.product ? ev.product.name : '');
            },

            // Nombres de todos los doctores de una cita (multi-doctor). Cae al
            // doctor_name de la fila si no hay lista de ids.
            appointmentDoctorNames(ev) {
                if (!ev) return '-';
                const ids = Array.isArray(ev.doctor_ids) ? ev.doctor_ids : [];
                if (ids.length) {
                    const names = ids
                        .map(id => (this.doctors.find(d => d && String(d.id) === String(id)) || {}).name)
                        .filter(Boolean);
                    if (names.length) return names.join(', ');
                }
                return ev.doctor_name || '-';
            },
            openEditModal(ev) {
                // Unificado: editar una cita usa el MISMO modal de detalle
                // (viewModal), en modo edición inline. Antes abría un modal aparte
                // (appointmentModal); ahora ver y editar comparten una sola UI.
                this.viewAppointment.data = ev;
                this.viewAppointment.visible = true;
                this.whatsappMenuOpen = false;
                this.loadApptHistory(ev.person_id);
                this.loadApptInsurance(ev.person_id);
                this.populateAppointmentForm(ev);
                this.editMode = true;
                this.$refs.viewModal.open();
            },
            openViewModal(ev) {
                this.viewAppointment.data = ev;
                this.viewAppointment.visible = true;
                this.whatsappMenuOpen = false;
                this.editMode = false;
                this.loadApptHistory(ev.person_id);
                this.loadApptInsurance(ev.person_id);
                this.$refs.viewModal.open();
            },

            loadApptHistory(personId) {
                this.apptHistory = [];
                if (!personId) return;
                this.apptHistoryLoading = true;
                this.$axios.get(this.apptHistoryUrlTemplate.replace('replaceId', personId))
                    .then(res => { this.apptHistory = res.data?.appointments ?? []; })
                    .catch(() => {})
                    .finally(() => { this.apptHistoryLoading = false; });
            },
            // Edit inline in the left column of the detail modal (no separate modal).
            editFromView() {
                const ev = this.viewAppointment.data;
                if (!ev) return;
                this.populateAppointmentForm(ev);
                this.editMode = true;
            },
            cancelInlineEdit() {
                this.editMode = false;
                this.modalError = '';
            },
            toggleWhatsappMenu() {
                this.whatsappMenuOpen = !this.whatsappMenuOpen;
            },
            closeWhatsappMenu() {
                this.whatsappMenuOpen = false;
            },
            // "Martes 14 de Julio". Distinto de formatDate(), que da el formato
            // corto que usa el resto del calendario.
            formatLongDate(dt) {
                const d = new Date(dt);
                const days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                const months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
                return `${days[d.getDay()]} ${d.getDate()} de ${months[d.getMonth()]}`;
            },
            buildWhatsappMessage(tpl, ev) {
                return tpl.body
                    .split(':fecha').join(this.formatLongDate(ev.start))
                    .split(':hora').join(this.formatTime(ev.start))
                    .split(':doctor').join(ev.doctor_name || '')
                    .split(':direccion').join(this.clinicAddress)
                    .split(':encuesta').join(this.surveyUrl);
            },
            // Current appointment state for the single-select control.
            apptState() {
                const d = this.viewAppointment.data;
                if (!d) return null;
                if (d.is_cancelled) return 'cancelled';
                if (d.is_lost) return 'no_show';
                if (d.is_completed) return 'completed';
                if (d.is_surgery) return 'surgery';
                if (d.is_confirmed) return 'confirmed';
                // Consulta (default) no tiene botón propio: no se resalta ninguno.
                return null;
            },

            // Initials + a stable flat color per name, matching Krayin's avatar.
            apptInitials(name) {
                return (name || '?').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
            },

            avatarColor(name) {
                const bg = ['bg-yellow-200', 'bg-red-200', 'bg-lime-200', 'bg-blue-200', 'bg-orange-200', 'bg-green-200', 'bg-pink-200', 'bg-yellow-400'];
                const tx = ['text-yellow-900', 'text-red-900', 'text-lime-900', 'text-blue-900', 'text-orange-900', 'text-green-900', 'text-pink-900', 'text-yellow-900'];
                let h = 0;
                for (const ch of String(name || '?')) h = (h * 31 + ch.charCodeAt(0)) % 997;
                return bg[h % bg.length] + ' ' + tx[h % tx.length];
            },

            // Abre el editor del motivo con el valor actual.
            startEditReason() {
                this.reasonDraft = this.viewAppointment.data.comment || '';
                this.editingReason = true;
            },

            // Guarda el motivo: actualiza la cita y la empuja a SMD. Reutiliza el
            // endpoint de edición mandando solo `reason` (los demás campos se
            // conservan en el backend).
            saveReason() {
                const ev = this.viewAppointment.data;
                if (!ev || this.reasonSaving) return;

                this.reasonSaving = true;

                this.$axios.post(this.appointmentUpdateUrlTemplate.replace('replaceId', ev.id), {
                        reason: this.reasonDraft,
                    })
                    .then(res => {
                        this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });
                        // Reflejar el cambio en el modal sin recargar todo.
                        this.viewAppointment.data.comment = this.reasonDraft;
                        this.editingReason = false;
                        this.fetch();
                    })
                    .catch(err => {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: err.response?.data?.message || 'No se pudo actualizar el motivo.',
                        });
                    })
                    .finally(() => { this.reasonSaving = false; });
            },

            // Busca pacientes por nombre para reasignar la cita.
            searchPatients() {
                const q = (this.patientQuery || '').trim();
                if (q.length < 2) { this.patientResults = []; return; }

                this.patientSearching = true;
                this.$axios.get(this.personSearchUrl, { params: { query: q } })
                    .then(res => { this.patientResults = res.data?.data || res.data || []; })
                    .catch(() => { this.patientResults = []; })
                    .finally(() => { this.patientSearching = false; });
            },

            cancelEditPatient() {
                this.editingPatient = false;
                this.patientQuery = '';
                this.patientResults = [];
            },

            // Reasigna la cita a otro paciente existente y la empuja a SMD.
            onChangePatient(result) {
                const ev = this.viewAppointment.data;
                if (!ev) return;

                if (!result?.id) {
                    this.$emitter.emit('add-flash', { type: 'error', message: 'Elegí un paciente ya registrado.' });
                    return;
                }

                this.$axios.post(this.appointmentUpdateUrlTemplate.replace('replaceId', ev.id), {
                        person_id: result.id,
                    })
                    .then(res => {
                        this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });
                        this.cancelEditPatient();
                        this.$refs.viewModal.close();
                        this.fetch();
                    })
                    .catch(err => {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: err.response?.data?.message || 'No se pudo cambiar el paciente.',
                        });
                    });
            },

            // Confirm / cancel the appointment (moves its lead to the mapped
            // stage). Refreshes the calendar so is_cancelled/is_completed update.
            setAppointmentStatus(status) {
                const ev = this.viewAppointment.data;
                if (!ev || this.statusUpdating) return;

                this.statusUpdating = true;

                this.$axios.post(this.appointmentStatusUrlTemplate.replace('replaceId', ev.id), { status })
                    .then(res => {
                        this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });
                        this.$refs.viewModal.close();
                        this.fetch();
                    })
                    .catch(err => {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: err.response?.data?.message || 'No se pudo actualizar el estado de la cita.',
                        });
                    })
                    .finally(() => { this.statusUpdating = false; });
            },

            // Send the reminder THROUGH the CRM gateway (Kommo/Cloud API) so it
            // lands in the patient's WhatsApp conversation and is logged - no
            // more wa.me deep link that needs WhatsApp Web open and a manual tap.
            openWhatsapp(tpl) {
                const ev = this.viewAppointment.data;
                if (!ev || !ev.person_whatsapp || this.reminderSending) return;

                this.reminderSending = true;

                this.$axios.post(this.reminderSendUrl, {
                        phone: ev.person_whatsapp,
                        name: ev.person_name || null,
                        person_id: ev.person_id || null,
                        text: this.buildWhatsappMessage(tpl, ev),
                    })
                    .then(res => {
                        this.$emitter.emit('add-flash', { type: 'success', message: res.data.message });
                        this.closeWhatsappMenu();
                    })
                    .catch(err => {
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: err.response?.data?.message || 'No se pudo enviar el recordatorio.',
                        });
                    })
                    .finally(() => { this.reminderSending = false; });
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

                if (!this.appointmentForm.doctor_ids || !this.appointmentForm.doctor_ids.length) {
                    this.modalSaving = false;
                    this.modalError = 'Debes seleccionar al menos un doctor.';
                    return;
                }


                const payload = {
                    person: {
                        id: this.appointmentForm.person.id || null,
                        name: this.appointmentForm.person.name || '',
                    },
                    doctor_ids: this.appointmentForm.doctor_ids,
                    product_id: this.appointmentForm.product_id,
                    lead_id: this.appointmentForm.lead_id || null,
                    date: this.modalContext.dayISO,
                    start_time: this.appointmentForm.startTime,
                    end_time: this.appointmentForm.endTime,
                    duration_minutes: this.appointmentForm.duration,
                    reason: this.appointmentForm.reason || '',
                    lead_pipeline_stage_id: this.appointmentForm.lead_pipeline_stage_id,
                };

                let promise;
                if (this.appointmentForm.id) {
                    // La edición se enruta por updateAppointment (SMD-safe): sincroniza
                    // participants (paciente) + fecha + doctores ANTES de empujar a SMD,
                    // que es lo que garantiza el round-trip. `update()` no maneja
                    // person_id, por eso no se usa aquí.
                    const url = this.appointmentUpdateUrlTemplate.replace('replaceId', this.appointmentForm.id);

                    const updatePayload = {
                        date: this.appointmentForm.date,
                        start_time: this.appointmentForm.startTime,
                        end_time: this.appointmentForm.endTime,
                        duration_minutes: this.appointmentForm.duration,
                        doctor_ids: this.appointmentForm.doctor_ids,
                        person_id: this.appointmentForm.person.id || null,
                        product_id: this.appointmentForm.product_id,
                        reason: this.appointmentForm.reason || '',
                        lead_pipeline_stage_id: this.appointmentForm.lead_pipeline_stage_id,
                    };
                    promise = this.$axios.post(url, updatePayload);
                } else {
                    promise = this.$axios.post(this.appointmentStoreUrl, payload);
                }

                promise
                    .then((response) => {
                        this.modalSaving = false;
                        try { localStorage.removeItem('ddc_appt_draft'); } catch (e) {}
                        this.$refs.appointmentModal.close();
                        // Inline edit (from the detail modal): leave edit mode and
                        // close the detail modal - the calendar refresh shows the change.
                        if (this.editMode) {
                            this.editMode = false;
                            this.$refs.viewModal.close();
                        }
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
            leadUrl(leadId) {
                if (!leadId) return null;
                return this.leadUrlTemplate.replace('replaceId', leadId);
            },
            goToLead(leadId) {
                const url = this.leadUrl(leadId);
                if (url) window.location.href = url;
            },
            onEventMouseEnter(e, evId, evData) {
                this.hoveredEventId = evId;
                this.hoveredEvent = evData || null;
                const rect = e.currentTarget.getBoundingClientRect();
                const tooltipW = 240;
                const left = (rect.right + 8 + tooltipW > window.innerWidth)
                    ? rect.left - tooltipW - 8
                    : rect.right + 8;
                this.tooltipPos = { top: rect.top, left };
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
    /* Accesibilidad (estándar salud): respetar quien pide menos movimiento. */
    @media (prefers-reduced-motion: reduce) {
        .ddc-container *,
        .ddc-container *::before,
        .ddc-container *::after {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
            scroll-behavior: auto !important;
        }
    }

    /* Foco visible de 3px con el color de marca (WCAG AAA) en los interactivos. */
    .ddc-container [role="button"]:focus-visible,
    .ddc-container button:focus-visible,
    .ddc-container .ddc-cell:focus-visible {
        outline: 3px solid var(--brand-color, #3b82f6);
        outline-offset: 2px;
    }

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
        background: color-mix(in srgb, var(--brand-color, #6750A4) 10%, transparent);
        border-color: color-mix(in srgb, var(--brand-color, #6750A4) 35%, transparent)
    }

    .ddc-date-day.is-selected {
        background: color-mix(in srgb, var(--brand-color, #6750A4) 18%, transparent);
        border-color: var(--brand-color, #6750A4);
        color: var(--brand-color, #6750A4);
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

    .ddc-wa-wrap {
        position: relative;
        margin-right: auto
    }

    /* Captura el click fuera para cerrar el desplegable, igual que ddc-quick-overlay. */
    .ddc-wa-overlay {
        position: fixed;
        inset: 0;
        z-index: 10;
        background: transparent
    }

    /* Se abre hacia arriba: el boton vive en el footer del modal. */
    .ddc-wa-menu {
        position: absolute;
        bottom: calc(100% + 6px);
        left: 0;
        min-width: 230px;
        background: #fff;
        border: 1px solid var(--border-color, #e5e7eb);
        border-radius: 10px;
        box-shadow: 0 16px 40px rgba(0, 0, 0, 0.18);
        padding: 4px;
        z-index: 20;
        overflow: hidden
    }

    .dark .ddc-wa-menu {
        background: #1f2937;
        border-color: #374151
    }

    .ddc-wa-menu-item {
        display: block;
        width: 100%;
        text-align: left;
        padding: 9px 12px;
        border: 0;
        border-radius: 6px;
        background: transparent;
        font-size: 14px;
        color: #111827;
        cursor: pointer
    }

    .ddc-wa-menu-item:hover {
        background: #f3f4f6
    }

    .dark .ddc-wa-menu-item {
        color: #f3f4f6
    }

    .dark .ddc-wa-menu-item:hover {
        background: #374151
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
        background: color-mix(in srgb, var(--brand-color, #6750A4) 10%, transparent)
    }

    .ddc-quick-menu-link {
        padding: 10px 8px;
        color: var(--brand-color, #6750A4);
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

    /* Cita cancelada (lead cerrado por status=0 o por stage cancelado/no-show,
       ver AppointmentController::get() -> is_cancelled). Mismo rojo en light/dark. */
    .dwc-event.dwc-event-cancelled {
        border-left-color: #ef4444;
        background: #fee2e2;
        color: #7f1d1d;
    }

    .dark .dwc-event.dwc-event-cancelled {
        background: #450a0a;
        color: #fecaca;
    }

    /* Cita en stage "Paciente (concretado)" (ver ActivityController::get() ->
       is_completed). Cancelado tiene prioridad si ambas coincidieran. */
    .dwc-event.dwc-event-completed {
        border-left-color: #22c55e;
        background: #dcfce7;
        color: #14532d;
    }

    .dark .dwc-event.dwc-event-completed {
        background: #052e16;
        color: #bbf7d0;
    }

    /* Cita confirmada (etapa "Cita confirmadas"). */
    .dwc-event.dwc-event-confirmed {
        border-left-color: #3b82f6;
        background: #ADD8E6;
        color: #0c4a6e;
    }

    /* Cirugía (etapa "Cirugía"). */
    .dwc-event.dwc-event-surgery {
        border-left-color: #f59e0b;
        background: #FFBB76;
        color: #7c2d12;
    }

    /* Cita perdida (etapa "Cita Perdida") - NO es cancelación, color propio. */
    .dwc-event.dwc-event-lost {
        border-left-color: #f87171;
        background: #FFAA8F;
        color: #7f1d1d;
    }

    .dwc-event-content {
        display: flex;
        flex-direction: column;
        gap: 2px;
        height: 100%;
        min-height: 0;
        position: relative;
        padding: 5px 8px;
        box-sizing: border-box;
    }

    /* En eventos cortos, apretamos el padding para que la primera línea no se
       corte a la mitad. */
    .dwc-event-compact .dwc-event-content {
        padding: 3px 8px;
        gap: 1px;
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
        min-height: 0;
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
        flex: 1 1 auto;
        min-width: 0;
        max-width: 100%;
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

    /* Evento corto (< ~45 min): el motivo no cabe sin desbordar, se oculta.
       El texto completo sigue disponible en el tooltip (title) y al abrir la cita. */
    .dwc-event-compact .dwc-event-reason {
        display: none;
    }

    /* Evento muy corto (< ~30 min): solo entra una línea; dejamos el nombre del
       paciente y el estado, ocultando la fila secundaria (doctor · hora · estado)
       para no cortar información a la mitad. */
    .dwc-event-tiny .dwc-event-row-secondary {
        display: none;
    }

    .dwc-event-tiny .dwc-event-patient {
        font-size: 11px;
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
        --event-accent: var(--brand-color, #3b82f6);
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
        gap: 12px;
        background: var(--hours-bg);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 14px;
    }

    /* Más aire entre celdas del mes (scopeado: no afecta el date-picker,
       que comparte .ddc-date-grid / .ddc-date-weekdays). */
    .dwc-month-view .ddc-date-weekdays,
    .dwc-month-view .ddc-date-grid {
        gap: 10px;
    }

    .dwc-month-view .ddc-date-weekday {
        font-size: 12px;
        font-weight: 600;
        text-transform: capitalize;
    }

    .dwc-month-cell {
        height: 156px;
        align-items: stretch;
        justify-content: flex-start;
        flex-direction: column;
        border-radius: 10px;
        border: 1px solid var(--border-color);
        overflow: hidden;
        transition: box-shadow .15s ease, border-color .15s ease;
    }

    .dwc-month-cell:hover {
        border-color: color-mix(in srgb, var(--brand-color, #6750A4) 35%, transparent);
        box-shadow: 0 1px 6px rgba(0, 0, 0, 0.06);
    }

    .dwc-month-cell.is-today {
        border-color: var(--brand-color, #3b82f6);
        background: rgba(59, 130, 246, 0.06);
    }

    .dwc-month-cell.is-out {
        background: var(--gray-50, rgba(0, 0, 0, 0.02));
        opacity: 0.6;
    }

    /* La regla base .ddc-date-day.is-out oculta el número (color: transparent);
       en el mes lo mostramos atenuado en vez de desaparecerlo. */
    .dwc-month-view .dwc-month-cell.is-out .dwc-month-cell-header {
        color: var(--hour-text, #9ca3af);
    }

    .dwc-month-cell-header {
        width: 100%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 6px 10px;
        font-size: 13px;
    }

    .dwc-month-cell-events {
        width: 100%;
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 0 8px 8px;
        overflow-y: auto;
        max-height: 118px;
    }

    .dwc-month-event {
        font-size: 11.5px;
        line-height: 1.35;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        padding: 3px 7px;
        border-radius: 6px;
        background: #dbeafe;
        color: #1e40af;
        border-left: 3px solid var(--brand-color, #3b82f6);
        cursor: pointer;
        transition: filter .15s ease;
    }

    .dwc-month-event:hover {
        filter: brightness(0.96);
    }

    .dark .dwc-month-event {
        background: #1e3a8a;
        color: #dbeafe;
        border-left-color: #60a5fa;
    }

    /* Colores por estado en la vista mes (mismos que día/semana). */
    .dwc-month-event.is-confirmed { background: #ADD8E6; color: #0c4a6e; border-left-color: #3b82f6; }
    .dwc-month-event.is-surgery   { background: #FFBB76; color: #7c2d12; border-left-color: #f59e0b; }
    .dwc-month-event.is-lost      { background: #FFAA8F; color: #7f1d1d; border-left-color: #f87171; }
    .dwc-month-event.is-completed { background: #DCFCE7; color: #14532d; border-left-color: #22c55e; }
    .dwc-month-event.is-cancelled { background: #f3f4f6; color: #6b7280; border-left-color: #9ca3af; text-decoration: line-through; opacity: 0.7; }

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

    .dwc-week-event.dwc-event-cancelled {
        background: #fee2e2;
        color: #7f1d1d;
    }

    .dark .dwc-week-event.dwc-event-cancelled {
        background: #450a0a;
        color: #fecaca;
    }

    .dwc-week-event.dwc-event-completed {
        background: #dcfce7;
        color: #14532d;
    }

    .dark .dwc-week-event.dwc-event-completed {
        background: #052e16;
        color: #bbf7d0;
    }

    .dwc-week-event.dwc-event-confirmed {
        background: #ADD8E6;
        color: #0c4a6e;
    }

    .dwc-week-event.dwc-event-surgery {
        background: #FFBB76;
        color: #7c2d12;
    }

    .dwc-week-event.dwc-event-lost {
        background: #FFAA8F;
        color: #7f1d1d;
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
        background-color: var(--brand-color, #3b82f6);
        color: white;
        font-weight: bold;
    }

    .dark .tp-list li.is-selected {
        background-color: var(--brand-color, #2563eb);
    }

    /* ===== Vista Lista por rango ===== */
    .dwc-list-view {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .dwc-list-filters {
        padding: 12px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fff;
    }

    .dark .dwc-list-filters {
        background: #111827;
        border-color: #1f2937;
    }

    .dwc-list-table {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
    }

    .dark .dwc-list-table {
        background: #111827;
        border-color: #1f2937;
    }

    .dwc-list-group-title {
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 600;
        text-transform: capitalize;
        color: #374151;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
    }

    .dark .dwc-list-group-title {
        color: #d1d5db;
        background: #1f2937;
        border-color: #374151;
    }

    .dwc-list-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        border-bottom: 1px solid #f3f4f6;
    }

    .dark .dwc-list-row {
        border-color: #1f2937;
    }

    .dwc-list-row:hover {
        background: #f9fafb;
    }

    .dark .dwc-list-row:hover {
        background: #1f2937;
    }

    .dwc-list-row.is-cancelled {
        opacity: 0.6;
        text-decoration: line-through;
    }

    .dwc-list-row.is-completed {
        background: #f0fdf4;
    }

    .dark .dwc-list-row.is-completed {
        background: rgba(22, 163, 74, 0.12);
    }

    .dwc-list-row.is-confirmed {
        background: #eff6ff;
        box-shadow: inset 3px 0 0 #3b82f6;
    }

    .dark .dwc-list-row.is-confirmed {
        background: rgba(59, 130, 246, 0.12);
    }

    .dwc-list-row.is-surgery {
        background: #fff7ed;
        box-shadow: inset 3px 0 0 #f59e0b;
    }

    .dark .dwc-list-row.is-surgery {
        background: rgba(245, 158, 11, 0.14);
    }

    .dwc-list-row.is-lost {
        background: #fff1f2;
        box-shadow: inset 3px 0 0 #f87171;
    }

    .dark .dwc-list-row.is-lost {
        background: rgba(248, 113, 113, 0.12);
    }

    .dwc-list-time {
        min-width: 120px;
        font-size: 13px;
        color: #374151;
        white-space: nowrap;
    }

    .dark .dwc-list-time {
        color: #d1d5db;
    }

    .dwc-list-main {
        flex: 1 1 auto;
        min-width: 0;
    }

    .dwc-list-actions {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .dwc-list-actions .dwc-action-btn {
        width: 32px;
        height: 32px;
        font-size: 16px;
    }
</style>
