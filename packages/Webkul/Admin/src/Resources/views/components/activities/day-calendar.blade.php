<script type="text/x-template" id="v-doctor-day-calendar-template">
    <div class="ddc-container">
        <div class="ddc-grid" :style="{ gridTemplateColumns: '80px repeat(' + columns.length + ', 1fr)' }">
            <div class="ddc-hours-head">Horas</div>
            <div class="ddc-head" v-for="doc in columns" :key="'head-'+doc.id">
                <div class="ddc-avatar"><span class="ddc-avatar-initial">@{{ doc && doc.name ? initial(doc.name) : '' }}</span></div>
                <div class="ddc-name">@{{ doc && doc.name ? doc.name : '' }}</div>
            </div>
            <div class="ddc-hours-col ddc-scroll">
                <div v-for="h in 24" :key="'hh-'+h" class="ddc-hour-label" :style="{ height: hourHeight + 'px' }">@{{ pad2(h-1) }}:00</div>
                <div class="ddc-now-line" :style="{ top: nowTop + 'px' }">
                    <span class="ddc-now-label-left">@{{ nowLabel }}</span>
                </div>
            </div>
            <div class="ddc-col" v-for="doc in columns" :key="'col-'+doc.id">
                <div
                    class="ddc-cell"
                    :style="{ height: dayHeight + 'px' }"
                    @click="onCellClick($event, doc.id)"
                    role="button"
                    tabindex="0"
                    :aria-label="'Seleccionar horario para ' + (doc && doc.name ? doc.name : 'doctor') + ' el ' + dayLabel"
                >
                    <div v-for="idx in 24" :key="'hline-'+idx" class="ddc-hour-line" :style="{ top: ((idx - 1) * hourHeight) + 'px' }"></div>
                    <div v-for="idx in 48" :key="'mline-'+idx" class="ddc-half-line" :style="{ top: ((idx - 1) * (hourHeight/2)) + 'px' }"></div>
                    <div
                        v-for="seg in dayDoctorUnavailableSegments(dateISO, doc.id)"
                        :key="'unav-'+dateISO+'-'+doc.id+'-'+seg.startMin"
                        class="ddc-unavailable"
                        :style="{ top: seg.top + 'px', height: seg.height + 'px' }"
                        aria-hidden="true"
                        :title="'No disponible · ' + (doc && doc.name ? doc.name : 'doctor')"
                    ></div>
                    <div class="ddc-now-line" :style="{ top: nowTop + 'px' }"></div>
                    <div v-for="ev in dayDoctorEvents(dateISO, doc.id)" :key="'ev-'+ev.id" class="ddc-event" :style="{ top: ev.top + 'px', height: ev.height + 'px' }">
                        <div class="ddc-event-title">@{{ ev.title || ev.type }}</div>
                        <div class="ddc-event-time">@{{ formatTime(ev.start) }} - @{{ formatTime(ev.end) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="addForm.visible" class="ddc-add-overlay" :style="{ top: addForm.top + 'px', left: addForm.left + 'px', width: overlayWidth + 'px' }">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-xs dark:text-gray-300">@{{ dayLabel }}</span>
                <span class="text-xs dark:text-gray-300">·</span>
                <span class="text-xs dark:text-gray-300">@{{ addForm.doctorLabel }}</span>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <label for="addFormTitle" class="sr-only">Título</label>
                <input type="text" id="addFormTitle" class="rounded border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="addForm.title" placeholder="Título" />
                <label for="addFormType" class="sr-only">Tipo</label>
                <select id="addFormType" class="rounded border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="addForm.type">
                    <option value="meeting">Consulta</option>
                    <option value="call">Llamada</option>
                    <option value="lunch">Almuerzo</option>
                </select>
                <label for="addFormStartTime" class="sr-only">Hora de inicio</label>
                <input type="time" id="addFormStartTime" class="rounded border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="addForm.startTime" />
                <label for="addFormEndTime" class="sr-only">Hora de fin</label>
                <input type="time" id="addFormEndTime" class="rounded border px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" v-model="addForm.endTime" />
            </div>
            <div class="mt-3 flex flex-col gap-2">
                <button type="button" class="qa-item" @click="addQuickAppointment">Añadir cita</button>
                <button type="button" class="qa-item" @click="addQuickGroup">Añadir cita de grupo</button>
                <button type="button" class="qa-item" @click="addQuickUnavailable">Añadir horario no disponible</button>
                <a class="qa-link" href="#">Ajustes de acciones rápidas</a>
            </div>
            <div class="mt-2 flex items-center gap-2">
                <button type="button" class="secondary-button" @click="cancelAdd">Cancelar</button>
                <button type="button" class="primary-button" @click="saveAdd" :disabled="isSaving">
                    <span v-if="!isSaving">Guardar</span>
                    <span v-else class="flex items-center gap-2"><x-admin::spinner /> Guardando...</span>
                </button>
            </div>
            <div class="mt-1 text-sm text-red-600" v-if="addError">@{{ addError }}</div>
        </div>
    </div>
</script>

<script type="module">
    app.component('v-doctor-day-calendar', {
        template: '#v-doctor-day-calendar-template',
        props: ['doctors', 'dateISO', 'appointments', 'availability', 'selectedIds'],
        data() {
            return {
                hourHeight: 64,
                isSaving: false,
                addError: '',
                overlayWidth: 320,
                overlayHeight: 180,
                dayLabel: '',
                nowTop: 0,
                nowLabel: '',
                nowTimer: null,
                addForm: {
                    visible: false,
                    doctorId: null,
                    doctorLabel: '',
                    top: 0,
                    left: 0,
                    title: '',
                    type: 'meeting',
                    startTime: '09:00',
                    endTime: '10:00',
                    group: false,
                },
                storeUrl: "{{ route('admin.activities.store') }}",
                scheduleStoreUrl: "{{ route('admin.schedules.store') }}",
            }
        },
        computed: {
            minuteHeight() {
                return this.hourHeight / 60
            },
            dayHeight() {
                return 24 * this.hourHeight
            },
            columns() {
                const ids = new Set((this.selectedIds || []).map(id => String(id)));
                return Array.isArray(this.doctors) ?
                    this.doctors.filter(d => d && d.id && ids.has(String(d.id))) :
                    [];
            }
        },
        mounted() {
            this.updateNow();
            this.nowTimer = setInterval(() => this.updateNow(), 60000);
            this.initScrollSync();
        },
        beforeUnmount() {
            if (this.nowTimer) {
                clearInterval(this.nowTimer);
                this.nowTimer = null
            }
            if (this._scrollEls) {
                this._scrollEls.forEach(el => el.removeEventListener('scroll', this._onScroll));
                this._scrollEls = null;
            }
        },
        methods: {
            initial(name) {
                return name ? (name || '').trim().charAt(0).toUpperCase() : ''
            },
            pad2(n) {
                return String(n).padStart(2, '0')
            },
            formatTime(dt) {
                const t = new Date(dt);
                return `${this.pad2(t.getHours())}:${this.pad2(t.getMinutes())}`
            },
            updateNow() {
                const d = new Date();
                const mins = d.getHours() * 60 + d.getMinutes();
                this.nowTop = mins * (this.hourHeight / 60);
                this.nowLabel = `${this.pad2(d.getHours())}:${this.pad2(d.getMinutes())}`;
            },
            initScrollSync() {
                const el = this.$el.querySelector('.ddc-scroll');
                if (el) {
                    el.scrollTop = 6 * this.hourHeight;
                    this._scrollEls = [el];
                    this._onScroll = null;
                }
            },
            dayDoctorEvents(dateStr, doctorId) {
                return this.appointments.filter(a => {
                    const d = a.start.split(' ')[0];
                    const matchDay = d === dateStr;
                    const matchDoctor = a.doctor_id === doctorId;
                    return matchDay && matchDoctor;
                }).map(a => {
                    const dtStart = new Date(a.start),
                        dtEnd = new Date(a.end);
                    const startMin = dtStart.getHours() * 60 + dtStart.getMinutes();
                    const endMin = dtEnd.getHours() * 60 + dtEnd.getMinutes();
                    const top = startMin * this.minuteHeight;
                    const height = Math.max((endMin - startMin) * this.minuteHeight, 8);
                    return {
                        ...a,
                        top,
                        height
                    };
                });
            },
            isSlotAvailable(dateStr, doctorId, startMin, endMin) {
                const unavailableSegments = this.dayDoctorUnavailableSegments(dateStr, doctorId);
                for (const seg of unavailableSegments) {
                    // Check for overlap
                    if (Math.max(startMin, seg.startMin) < Math.min(endMin, seg.endMin)) {
                        return false; // Overlaps with an unavailable segment
                    }
                }
                return true; // No overlap, slot is available
            },
            dayDoctorUnavailableSegments(dateStr, doctorId) {
                const shifts = this.availability.filter(s => String(s.doctor_id) === String(doctorId) && s
                        .date === dateStr)
                    .map(s => {
                        const [hs, ms] = String(s.start_time).split(':').map(Number);
                        const [he, me] = String(s.end_time).split(':').map(Number);
                        return {
                            startMin: hs * 60 + ms,
                            endMin: he * 60 + me
                        };
                    })
                    .sort((a, b) => a.startMin - b.startMin);
                const merged = [];
                for (const iv of shifts) {
                    if (!merged.length || iv.startMin > merged[merged.length - 1].endMin) {
                        merged.push({
                            ...iv
                        });
                    } else {
                        merged[merged.length - 1].endMin = Math.max(merged[merged.length - 1].endMin, iv
                        .endMin);
                    }
                }
                const segments = [];
                let cursor = 0;
                for (const iv of merged) {
                    if (cursor < iv.startMin) {
                        const start = cursor,
                            end = iv.startMin;
                        segments.push({
                            startMin: start,
                            endMin: end,
                            top: start * this.minuteHeight,
                            height: Math.max((end - start) * this.minuteHeight, 2)
                        });
                    }
                    cursor = Math.max(cursor, iv.endMin);
                }
                if (cursor < 1440) {
                    const start = cursor,
                        end = 1440;
                    segments.push({
                        startMin: start,
                        endMin: end,
                        top: start * this.minuteHeight,
                        height: Math.max((end - start) * this.minuteHeight, 2)
                    });
                }
                return segments;
            },
            onCellClick(e, doctorId) {
                const rect = e.currentTarget.getBoundingClientRect();
                const y = e.clientY - rect.top;
                const minutes = Math.max(0, Math.min(23 * 60 + 59, Math.round(y / this.minuteHeight)));
                const h = Math.floor(minutes / 60),
                    m = minutes % 60;
                const startMins = minutes;
                const endMins = Math.min(23 * 60 + 59, minutes +
                60); // Assume a default 1-hour appointment slot for checking

                if (!this.isSlotAvailable(this.dateISO, doctorId, startMins, endMins)) {
                    alert('Este horario no está disponible para pedidos.'); // Simple feedback
                    return;
                }

                this.addForm.visible = true;
                const xView = e.clientX,
                    yView = e.clientY;
                const desiredLeft = xView + 8;
                const maxLeft = window.innerWidth - this.overlayWidth - 8;
                let left = desiredLeft <= maxLeft ? desiredLeft : Math.max(8, xView - this.overlayWidth - 8);
                const desiredTop = yView - this.overlayHeight - 8;
                const minTop = 8;
                const maxTop = window.innerHeight - this.overlayHeight - 8;
                let top = desiredTop >= minTop ? desiredTop : Math.min(maxTop, yView + 8);
                this.addForm.left = left;
                this.addForm.top = top;
                this.addForm.doctorId = doctorId;
                const doc = this.doctors.find(d => d.id === doctorId);
                this.addForm.doctorLabel = (doc?.name || '');
                this.dayLabel = new Date(this.dateISO).toLocaleDateString(undefined, {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric'
                });
                this.addForm.startTime = `${this.pad2(h)}:${this.pad2(m)}`;
                const endMins = Math.min(23 * 60 + 59, minutes + 60),
                    eh = Math.floor(endMins / 60),
                    em = endMins % 60;
                this.addForm.endTime = `${this.pad2(eh)}:${this.pad2(em)}`;
                this.addForm.title = '';
                this.addForm.type = 'meeting';
                this.addError = '';
            },
            cancelAdd() {
                this.addForm.visible = false;
                this.addError = ''
            },
            saveAdd() {
                this.isSaving = true;
                const start = `${this.dateISO} ${this.addForm.startTime}`,
                    end = `${this.dateISO} ${this.addForm.endTime}`;
                const payload = {
                    type: this.addForm.type,
                    title: this.addForm.title,
                    schedule_from: start,
                    schedule_to: end
                };
                if (this.addForm.group) {
                    payload['participants'] = {
                        doctors: (this.selectedIds || []).map(id => Number(id))
                    };
                } else if (this.addForm.doctorId) {
                    payload['participants'] = {
                        doctors: [this.addForm.doctorId]
                    };
                }
                this.$axios.post(this.storeUrl, payload).then(() => {
                        this.isSaving = false;
                        this.addForm.visible = false;
                        this.$emit('refresh')
                    })
                    .catch(err => {
                        this.isSaving = false;
                        this.addError = err?.response?.data?.message || 'Error al guardar'
                    });
            },
            addQuickAppointment() {
                this.addForm.group = false;
                this.saveAdd();
            },
            addQuickGroup() {
                this.addForm.group = true;
                this.saveAdd();
            },
            addQuickUnavailable() {
                this.$axios.post(this.scheduleStoreUrl, {
                    doctor_id: this.addForm.doctorId,
                    date: this.dateISO,
                    start_time: this.addForm.startTime,
                    end_time: this.addForm.endTime
                }).then(() => {
                    this.addForm.visible = false;
                    this.$emit('refresh');
                });
            },
        }
    });
</script>

<style>
    .ddc-container {
        display: flex;
        flex-direction: column;
        gap: 8px;
        overflow-x: auto
    }

    .ddc-grid {
        display: grid;
        gap: 8px
    }

    .ddc-head {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        margin-bottom: 12px
    }

    .ddc-hours-head {
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: var(--hour-text)
    }

    .ddc-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #eee;
        margin: 0 auto 4px
    }

    .ddc-name {
        font-size: 12px;
        font-weight: 600
    }

    .ddc-col {
        position: relative
    }

    .ddc-cell {
        background-color: #FFFFFF;
    }

    .ddc-hours-col {
        position: relative
    }

    .ddc-scroll {
        max-height: 450px;
        overflow-y: auto
    }

    .ddc-hour-label {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding-right: 6px;
        font-size: 12px;
        color: var(--hour-text)
    }

    .ddc-col {
        position: relative
    }

    .ddc-hour-line {
        position: absolute;
        left: 0;
        right: 0;
        height: 1px;
        background: var(--grid-line)
    }

    .ddc-half-line {
        position: absolute;
        left: 0;
        right: 0;
        height: 1px;
        background: var(--grid-line);
        opacity: .6
    }

    .ddc-unavailable {
        position: absolute;
        left: 0;
        right: 0;
        background: #F3F4F6;
        opacity: 1;
        pointer-events: none;
        border-radius: 2px
    }

    .ddc-event {
        position: absolute;
        left: 6px;
        right: 6px;
        border-left: 4px solid var(--event-accent);
        background: var(--event-bg);
        color: var(--event-text);
        border-radius: 6px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        padding: 6px 8px;
        font-size: 12px
    }

    .ddc-now-line {
        position: absolute;
        left: 0;
        right: 0;
        border-top: 2px solid #DC2626
    }

    .ddc-now-label {
        position: absolute;
        left: -28px;
        top: -10px;
        background: #fff;
        color: #DC2626;
        border: 2px solid #DC2626;
        border-radius: 12px;
        padding: 0 6px;
        font-size: 11px
    }

    .ddc-event-title {
        font-weight: 600
    }

    .ddc-event-time {
        font-size: 11px;
        opacity: .85
    }

    .ddc-add-overlay {
        position: fixed;
        background: var(--hours-bg);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 8px;
        z-index: 9999;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18)
    }

    .ddc-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #E8E8E8;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 4px;
        border: 1px solid var(--border-color)
    }

    .ddc-avatar-initial {
        font-weight: 700;
        color: #333
    }

    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border-width: 0;
    }
</style>
</style>
