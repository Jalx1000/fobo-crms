<?php

/*
| Modal "Detalles de la cita". Antes: un toque en "Cancelada" cancelaba sin
| preguntar y cerraba el modal; el badge y el historial solo conocían 3 estados;
| editar no avisaba de turno ni choques (solo al crear); había dos buscadores de
| paciente; el historial no se podía abrir; y en el celular el chat quedaba
| debajo de todo.
*/

function detailModalView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/components/activities/doctor-day-calendar.blade.php');
}

function detailModalMarkup(): string
{
    $view = detailModalView();
    $start = strpos($view, '<x-admin::modal ref="viewModal" size="large">');

    return substr($view, $start, strpos($view, '<x-admin::modal ref="groupModal">', $start) - $start);
}

function detailEditMarkup(): string
{
    $modal = detailModalMarkup();
    $start = strpos($modal, '<!-- Modo edición inline');

    return substr($modal, $start, strpos($modal, '<!-- /Columna izquierda -->', $start) - $start);
}

function detailModalMethod(string $signature): string
{
    preg_match('/'.preg_quote($signature, '/').'(.*?)\n            \},/s', detailModalView(), $m);

    return $m[1] ?? '';
}

it('cancelar o marcar perdida pide confirmación; los demás estados van directo', function () {
    $method = detailModalMethod('setAppointmentStatus(status) {');

    expect($method)->toContain("if (status === 'cancelled' || status === 'no_show') {")
        ->toContain("this.\$emitter.emit('open-confirm-modal', {")
        ->toContain('agree: () => this.sendAppointmentStatus(status),')
        ->toContain('this.sendAppointmentStatus(status);');
});

it('tocar el estado que ya tiene la cita no hace nada', function () {
    expect(detailModalMethod('setAppointmentStatus(status) {'))
        ->toContain('if (!ev || this.statusUpdating || this.apptState() === status) return;');
});

it('cambiar el estado deja el modal abierto y lo actualiza', function () {
    $send = detailModalMethod('sendAppointmentStatus(status) {');

    expect($send)->not->toContain('viewModal.close()')
        ->toContain('this.applyAppointmentState(ev, status);')
        ->toContain('this.fetch()?.then(() => this.refreshViewAppointment(ev.id));')
        ->and(detailModalMethod('fetch() {'))->toContain('return this.$axios.get(this.endpoint, {');

    // Las etapas de cada estado vienen del config, en una sola línea (un @json multilínea rompe Blade).
    expect(detailModalView())->toMatch("/stageMap: \{!! json_encode\(\[[^\n]*\]\) !!\},\n/");
});

it('barra, badge e historial usan la misma lista de estados con color inline', function () {
    $modal = detailModalMarkup();

    expect($modal)->toContain('v-for="st in apptStates"')
        ->toContain(':style="apptStateBadgeStyle(apptState())"')
        ->toContain(':style="apptStateBadgeStyle(a.state)">@{{ apptStateMeta(a.state).label }}</span>')
        // Sin los ternarios de 3 colores de antes.
        ->not->toContain("a.state === 'cancelled' ? 'Cancelada'")
        ->not->toContain('viewAppointment.data.is_completed');

    expect(detailModalView())->toContain("{ key: 'no_show',   label: 'Cita perdida', color: '#fb923c', fill: '#fed7aa', text: '#7c2d12' },")
        ->toContain("{ key: 'surgery',   label: 'Cirugía',      color: '#fbbf24', fill: '#fde68a', text: '#78350f' },")
        ->toContain("return { '--st-color': meta.color, '--st-fill': meta.fill, '--st-text': meta.text };");
});

it('el título dice qué cita es y fecha y horario van en una fila', function () {
    $modal = detailModalMarkup();

    expect($modal)->not->toContain('Detalles de la Cita')
        ->toContain('<span>Cita</span>')
        ->toContain('· @{{ apptWhenLabel(viewAppointment.data) }}</span>')
        ->toContain('>Cuándo</span>')
        ->not->toContain('>Horario</span>');
});

it('editar revisa turno y choques como "Nueva cita", sin chocar consigo misma', function () {
    $edit = detailEditMarkup();

    expect($edit)->toContain('@keydown.ctrl.enter="saveAppointment" @keydown.meta.enter="saveAppointment"')
        ->toContain('<div v-if="appointmentSlotIssue" class="ddc-appt-issue" role="alert">')
        ->toContain('v-for="m in appointmentFreeSlots"')
        ->toContain(':step="slotMinutes"')
        ->toContain('Termina a las @{{ appointmentForm.endTime }}')
        ->toContain('v-model="modalContext.dayISO"')
        ->toContain('<li v-for="(msg, i) in modalErrorDetails"');

    expect(detailModalMarkup())->toContain(':disabled="modalSaving || !!appointmentSlotIssue" @click="saveAppointment">');

    expect(detailModalView())
        ->toContain('busyIntervals(dateStr, doctorId, excludeId = null) {')
        ->toContain('.filter(a => excludeId == null || String(a.id) !== String(excludeId))')
        ->toContain('this.busyIntervals(date, doctorId, this.appointmentForm.id || null)')
        ->toContain('Number(this.appointmentForm.duration) || 60, this.appointmentForm.id || null);')
        ->and(detailModalMethod('saveAppointment() {'))->toContain('date: this.modalContext.dayISO,');
});

it('editar solo el motivo no exige que el horario esté en turno', function () {
    expect(detailModalView())
        ->toContain('if (! this.appointmentSlotChanged) return null;')
        ->toMatch('/appointmentSlotChanged\(\) \{.*?o\.date !== this\.modalContext\.dayISO.*?o\.startTime !== this\.appointmentForm\.startTime.*?o\.endTime !== this\.appointmentForm\.endTime.*?String\(o\.doctorId\) !== String\(this\.appointmentPrincipalDoctorId\)/s')
        ->and(detailModalMethod('populateAppointmentForm(ev) {'))->toContain('this.appointmentOriginal = {');
});

it('al editar, el estado no ofrece cancelar ni perdida (van por la barra con confirmación)', function () {
    expect(detailEditMarkup())->toContain('v-for="stage in editableStages"')
        ->not->toContain('v-for="stage in stages"')
        ->and(detailModalView())->toContain('return this.stages.filter(s => String(s.id) === current || creatable.has(String(s.id)));');
});

it('el formulario de edición es de una columna en el celular', function () {
    expect(detailEditMarkup())->toContain('<div class="ddc-appt-grid">')
        ->not->toContain('grid grid-cols-2')
        ->not->toContain('col-span-2');
});

it('hay un solo buscador de paciente: "Cambiar" abre la edición con el foco ahí', function () {
    $view = detailModalView();

    expect(detailModalMarkup())->toContain('@click="changePatientFromView"')
        ->toContain('ref="apptEditPatientField"')
        ->not->toContain('patientQuery');

    expect($view)->not->toContain('searchPatients()')
        ->not->toContain('onChangePatient(')
        ->and(detailModalMethod('changePatientFromView() {'))
        ->toContain('this.editFromView();')
        ->toContain('this.$refs.apptEditPatientField?.querySelector(');
});

it('el historial se abre al tocar una fila y marca la cita actual', function () {
    $modal = detailModalMarkup();

    expect($modal)->toContain('@click="openHistoryAppointment(a)"')
        ->toContain('<span v-if="isCurrentHistoryRow(a)" class="ml-1 font-medium text-gray-700 dark:text-gray-200">· esta</span>')
        ->toContain('v-else-if="apptOtherHistoryCount"');

    $open = detailModalMethod('openHistoryAppointment(a) {');
    expect($open)->toContain('this.openViewModal(loaded);')
        ->toContain('this.pendingOpenAppointmentId = a.id;')
        ->toContain('this.goToDay(a.date_iso);')
        ->and(detailModalView())->toContain('if (pending) this.$nextTick(() => this.openViewModal(pending));');
});

it('el teléfono del paciente se puede llamar', function () {
    expect(detailModalMarkup())->toContain(':href="\'tel:+\' + viewAppointment.data.person_whatsapp"');
});

it('en celular y tablet el detalle y el chat van en pestañas', function () {
    $view = detailModalView();

    expect(detailModalMarkup())->toContain('<div class="ddc-view-tabs" role="group" aria-label="Secciones de la cita">')
        ->toContain(':aria-pressed="viewTab === \'chat\'"')
        ->toContain(":class=\"{ 'is-tab-hidden': viewTab !== 'cita' }\"")
        ->toContain(":class=\"{ 'is-tab-hidden': viewTab !== 'chat' }\"");

    expect($view)->toContain('.ddc-view-tabs { display: none; }')
        ->toContain('.ddc-view-col.is-tab-hidden { display: none !important; }')
        ->and(detailModalMethod('openViewModal(ev) {'))->toContain("this.viewTab = 'cita';");
});

it('el pie deja Editar como acción principal y quita el Cerrar duplicado', function () {
    $footer = substr(detailModalMarkup(), strpos(detailModalMarkup(), '<!-- Footer en modo detalle -->'));

    expect($footer)->toContain('<button type="button" class="primary-button" @click="editFromView">Editar</button>')
        ->not->toContain('>Cerrar</button>');
});
