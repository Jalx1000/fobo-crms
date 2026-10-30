<?php

/*
| Vista Semana. Con varios doctores (tabla doctor × día) el click en cualquier
| celda abría "Añadir cita" a las 09:00 fijas, aunque el doctor no tuviera turno
| ese día o su turno empezara a las 15:00. La línea de "ahora" solo salía si
| startISO era hoy, y en la columna de horas en vez de la del día.
*/

function weekView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/components/activities/doctor-day-calendar.blade.php');
}

function weekMethod(string $signature): string
{
    preg_match('/'.preg_quote($signature, '/').'(.*?)\n            \},/s', weekView(), $m);

    return $m[1] ?? '';
}

it('un día sin turno va rayado y no abre "Añadir cita"', function () {
    expect(weekView())
        ->toContain(":class=\"{ 'is-today': day.date === todayISO(), 'is-off': !hasShift(day.date, col.id) }\"")
        ->toMatch('/\.dwc-week-day-cell\.is-off \{[^}]*repeating-linear-gradient\(-45deg[^}]*cursor: not-allowed;/');

    expect(weekMethod('onWeekCellClick(e, day, doctorId) {'))
        ->toContain('if (! this.hasShift(day.date, doctorId)) return;');
});

it('el click propone el primer horario libre, no las 09:00 fijas', function () {
    $click = weekMethod('onWeekCellClick(e, day, doctorId) {');

    expect($click)->toContain('this.firstFreeSlot(day.date, doctorId)')
        ->not->toContain("startTime = '09:00'")
        // Día lleno: inicio del turno, avisado.
        ->toContain('sin horario libre');
});

it('el hueco libre mira todas las citas del doctor y las canceladas o perdidas no ocupan', function () {
    $busy = weekMethod('busyIntervals(dateStr, doctorId, excludeId = null) {');
    $free = weekMethod('freeSlots(dateStr, doctorId, duration, excludeId = null) {');
    $first = weekMethod('firstFreeSlot(dateStr, doctorId, duration = 60) {');

    // this.appointments y no visibleAppointments: el filtro de estado no libera horarios.
    expect($busy)->toContain('return this.appointments')
        ->not->toContain('visibleAppointments')
        // Igual que AppointmentService: canceladas y no_show no bloquean.
        ->toContain('! a.is_cancelled && ! a.is_lost && String(a.doctor_id) === String(doctorId)')
        ->and($free)->toContain('! busy.some(b => m < b.to && m + duration > b.from)')
        // Si no hay hueco de una hora, uno de slotMinutes.
        ->and($first)->toContain('for (const len of [duration, this.slotMinutes]) {');
});

it('con el buscador de paciente activo no se afirma que un horario está libre', function () {
    // Con búsqueda solo llegan las citas de ese paciente: las demás no se ven.
    expect(weekMethod('onWeekCellClick(e, day, doctorId) {'))
        ->toContain("const free = this.patientSearch.trim() === '' ? this.firstFreeSlot(day.date, doctorId) : null;");
});

it('la línea de ahora sale si hoy está en el rango y se dibuja en la columna de hoy', function () {
    $view = weekView();

    expect($view)->toContain('return this.days.some(d => d.date === this.todayISO())')
        ->not->toContain('return this.startISO === this.todayISO() && this.nowMinutes')
        ->and(substr_count($view, '<div v-if="showNowLine && day.date === todayISO()" class="dwc-now-line dwc-now-line-day"'))->toBe(2);
});

it('cada cita muestra el paciente y el estado en texto', function () {
    expect(weekView())
        ->toContain('@{{ ev.person_name ? formatPatientName(ev.person_name) : (ev.title || ev.type) }}')
        ->toContain('<span v-if="getStageName(ev.lead_pipeline_stage_id)" class="dwc-week-event-status truncate">');
});

it('hoy se destaca en el encabezado y el encabezado y los doctores quedan fijos', function () {
    $view = weekView();

    expect($view)->toContain("class=\"dwc-week-header-cell\" :class=\"{ 'is-today': day.date === todayISO() }\"")
        ->toContain("class=\"dwc-doctor-header justify-center\" :class=\"{ 'is-today': day.date === todayISO() }\"")
        ->toMatch('/\.dwc-week-header \{[^}]*position: sticky;\s*top: 0;/')
        ->toMatch('/\.dwc-week-doctor-cell \{[^}]*position: sticky;\s*left: 0;/');
});

it('"1 cita" en singular', function () {
    $view = weekView();

    expect($view)->toContain("return `\${n} \${n === 1 ? 'cita' : 'citas'}`;")
        ->not->toContain('}} citas</span>');
});

it('la semana de un doctor entra en 1366px sin scroll horizontal', function () {
    expect(weekView())->toContain('return `80px repeat(${this.days.length}, minmax(140px, 1fr))`;')
        ->not->toContain('minmax(200px, 1fr)');
});
