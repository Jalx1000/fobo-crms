<?php

/*
| Modal "Nueva cita" del calendario. Antes: el encabezado no seguía a la hora
| elegida, la verificación automática del seguro nunca corría, el selector de
| doctores mostraba un "0", los errores por campo se perdían, el borrador nunca
| se guardaba (el watcher estaba en v-time-picker) y la hora se elegía a ciegas
| hasta que el backend rechazaba la cita fuera de turno o con choque.
*/

function appointmentModalView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/components/activities/doctor-day-calendar.blade.php');
}

function appointmentModalMarkup(): string
{
    $view = appointmentModalView();
    $start = strpos($view, '<x-admin::modal ref="appointmentModal">');

    return substr($view, $start, strpos($view, '</x-admin::modal>', $start) - $start);
}

function appointmentModalMethod(string $signature): string
{
    preg_match('/'.preg_quote($signature, '/').'(.*?)\n            \},/s', appointmentModalView(), $m);

    return $m[1] ?? '';
}

it('el encabezado sigue a la fecha y a la hora elegidas', function () {
    expect(appointmentModalMarkup())
        ->toContain('@{{ appointmentDayLabel }} · @{{ appointmentForm.startTime }}–@{{ appointmentForm.endTime }}')
        ->not->toContain('modalContext.timeText');
});

it('la fecha se puede cambiar y la hora de fin es texto, no un campo desactivado', function () {
    $modal = appointmentModalMarkup();

    expect($modal)->toContain('type="date"')
        ->toContain('v-model="modalContext.dayISO"')
        ->toContain('Termina a las @{{ appointmentForm.endTime }}')
        ->not->toContain('v-model="appointmentForm.endTime"');
});

it('la hora de inicio se elige de a 15 minutos', function () {
    $view = appointmentModalView();

    expect(appointmentModalMarkup())->toContain(':step="slotMinutes"')
        ->and($view)->toContain('<li v-for="m in minuteOptions"')
        ->toContain('return Array.from({ length: Math.ceil(60 / step) }, (_, i) => i * step);');
});

it('ofrece los horarios libres del doctor principal y avisa fuera de turno o con choque', function () {
    $modal = appointmentModalMarkup();
    $view = appointmentModalView();

    expect($modal)->toContain('v-for="m in appointmentFreeSlots"')
        ->toContain('@click="pickAppointmentSlot(m)"')
        ->toContain('<div v-if="appointmentSlotIssue" class="ddc-appt-issue" role="alert">')
        // El backend rechaza estos casos: Guardar se bloquea antes.
        ->toContain(':disabled="modalSaving || !!appointmentSlotIssue"');

    expect($view)->toContain('está fuera del turno de ${this.appointmentPrincipalDoctorName} ese día.')
        ->toContain('const clash = this.busyIntervals(date, doctorId, this.appointmentForm.id || null).find(b => from < b.to && to > b.from);')
        ->toContain('return (this.appointmentForm.doctor_ids || [])[0] ?? null;');
});

it('sin la agenda del día cargada (o con el buscador activo) no bloquea: avisa que se revisa al guardar', function () {
    expect(appointmentModalView())
        ->toMatch("/appointmentAgendaKnown\(\) \{\s*return this\.patientSearch\.trim\(\) === ''\s*&& this\.days\.some\(d => d\.date === this\.modalContext\.dayISO\);/")
        ->and(appointmentModalMarkup())->toContain('el turno y los choques se revisan al guardar.');
});

it('Ctrl/Cmd+Enter guarda con el mismo bloqueo que el botón', function () {
    expect(appointmentModalMarkup())->toContain('@keydown.ctrl.enter="saveAppointment" @keydown.meta.enter="saveAppointment"')
        ->and(appointmentModalMethod('saveAppointment() {'))
        ->toContain('if (this.appointmentSlotIssue) return;');
});

it('al crear no se ofrecen Cancelado, Completado ni Cita perdida', function () {
    expect(appointmentModalMarkup())->toContain('v-for="stage in creatableStages"')
        ->and(appointmentModalView())
        ->toContain('const confirmedStage = this.creatableStages.find(s => /confirm/i.test(s.name));');
});

it('al elegir paciente se precarga su seguro guardado y se verifica solo', function () {
    $select = appointmentModalMethod('onSelectPatient(result) {');

    expect($select)->toContain("this.\$axios.get(this.insuranceStatusUrlTemplate.replace('replaceId', personId))")
        ->toContain('if (saved) this.insurance.seguro_id = saved.id;')
        ->toContain('if (this.insurance.ci && this.insurance.seguro_id) this.verifyInsurance();')
        // Respuesta tardía de otro paciente: se descarta.
        ->toContain('if (String(this.appointmentForm.person.id) !== String(personId)) return;')
        ->not->toContain('if (result?.ci_paciente && this.insurance.seguro_id) {');
});

it('los errores de validación se muestran campo por campo', function () {
    expect(appointmentModalView())
        ->toContain('const fieldErrors = data.errors ? Object.values(data.errors).flat() : [];')
        ->and(appointmentModalMarkup())->toContain('<li v-for="(msg, i) in modalErrorDetails"');
});

it('el borrador se guarda desde el calendario y avisa cuando se recupera', function () {
    $view = appointmentModalView();

    $picker = substr($view, strpos($view, "app.component('v-time-picker', {"), 3000);
    $calendar = substr($view, strpos($view, "app.component('v-doctor-day-calendar', {"));

    expect($picker)->not->toContain('ddc_appt_draft')
        ->and($calendar)->toContain("try { localStorage.setItem('ddc_appt_draft', JSON.stringify(val)); } catch (e) {}")
        ->and(appointmentModalMarkup())->toContain('<div v-if="draftRestored" class="ddc-appt-draft" role="status">')
        ->toContain('@click="discardAppointmentDraft">Descartar</button>');
});

it('el selector de doctores ya no muestra "0" con uno o dos elegidos', function () {
    expect(appointmentModalView())
        ->toContain('<span class="ms-count" v-if="model.length > 2">+@{{ model.length - 2 }}</span>')
        ->not->toContain('model.length > 2 ? model.length - 2 : 0');
});

it('doctor es obligatorio y en el celular el formulario va en una columna', function () {
    $view = appointmentModalView();

    expect(appointmentModalMarkup())->toContain('<x-admin::form.control-group.label class="required">Doctor(es)</x-admin::form.control-group.label>')
        ->not->toContain('grid grid-cols-2')
        ->and($view)->toContain('.ddc-appt-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }')
        ->toContain('.ddc-appt-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }');
});

it('las etapas excluidas se imprimen con un echo de una línea (un @json de varias líneas no compila)', function () {
    expect(appointmentModalView())
        ->toContain("nonCreatableStageIds: {!! json_encode(array_values(array_filter([(int) config('smd.stage_map.cancelled'), (int) config('smd.stage_map.completed'), (int) config('smd.stage_map.no_show')]))) !!},")
        ->not->toMatch('/nonCreatableStageIds: @json\(/');
});
