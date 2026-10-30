<?php

/*
| Vista Mes. Antes: empezaba en domingo (Semana y el backend empiezan en lunes),
| desde un día 31 "mes anterior" se quedaba en el mismo mes, cada día hacía scroll
| por dentro con todas sus citas (title en vez de paciente), un día no llevaba a
| ningún lado y en el celular cada día medía ~37px con el texto de las citas.
*/

function monthView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/components/activities/doctor-day-calendar.blade.php');
}

function monthMarkup(): string
{
    $view = monthView();
    $start = strpos($view, '<div v-if="viewType === \'month\'" class="dwc-month-view"');

    return substr($view, $start, strpos($view, '<div v-else-if="viewType === \'list\'"', $start) - $start);
}

it('la semana empieza en lunes en el mes y en el selector de fechas', function () {
    expect(monthView())
        ->toContain("weekdays: ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'],")
        ->toContain('const offset = (first.getDay() + 6) % 7;')
        ->not->toContain('const offset = first.getDay();');
});

it('mes anterior/siguiente parte del día 1 (desde un 31 se quedaba en el mismo mes)', function () {
    expect(substr_count(monthView(), "if (this.viewType === 'month') d.setDate(1);"))->toBe(2);
});

it('cada día muestra hasta 3 citas con el paciente y un "+N más" que lleva a la vista Día', function () {
    $month = monthMarkup();

    expect($month)->toContain('v-for="ev in getEventsForDay(cell.iso).slice(0, monthMaxEvents)"')
        ->toContain('@{{ monthEventPatient(ev) }}')
        ->toContain('@click.stop="goToDay(cell.iso)"')
        ->toContain('+@{{ getEventsForDay(cell.iso).length - monthMaxEvents }} más')
        ->not->toContain('@{{ ev.title || ev.type }}')
        ->and(monthView())->toContain('monthMaxEvents: 3,')
        ->not->toContain('max-height: 118px;');
});

it('el número del día lleva a la vista Día en escritorio y elige el día en el celular', function () {
    preg_match('/onMonthDayClick\(iso\) \{(.*?)\n            \},/s', monthView(), $m);

    expect($m[1])->toContain("if (window.matchMedia('(max-width: 639px)').matches) {")
        ->toContain('this.monthPicked = iso;')
        ->toContain('this.goToDay(iso);')
        ->and(monthView())->toMatch("/goToDay\(iso\) \{\s*if \(!iso\) return;\s*this\.startISO = iso;\s*this\.setView\('day'\);/");
});

it('el número de citas va en el día y los días sin turnos van rayados', function () {
    $month = monthMarkup();

    expect($month)->toContain('class="dwc-month-count"')
        ->toContain("'is-off': cell.inMonth && !dayHasShift(cell.iso)")
        ->and(monthView())->toMatch('/\.dwc-month-cell\.is-off \{[^}]*repeating-linear-gradient\(-45deg/');
});

it('en el celular el mes es compacto y las citas del día elegido van debajo', function () {
    $view = monthView();
    $mobile = substr($view, strpos($view, '/* Punto de "tiene citas" y lista del día: solo en el celular. */'), 4000);

    expect(monthMarkup())->toContain('<section class="dwc-month-agenda"')
        ->toContain('v-for="ev in getEventsForDay(monthPickedISO)"')
        ->toContain('Sin citas este día.');

    expect($mobile)->toContain('@media (max-width: 639px)')
        ->toContain('.dwc-month-cell { height: 48px;')
        ->toContain('.dwc-month-cell-events, .dwc-month-count { display: none; }')
        // Toda la celda se puede tocar.
        ->toContain(".dwc-month-day-btn::after { content: ''; position: absolute; inset: 0; }");
});

it('días y citas son botones con nombre accesible', function () {
    $month = monthMarkup();

    expect($month)->toContain('class="dwc-month-day-btn"')
        ->toContain(":aria-current=\"cell.iso === todayISO() ? 'date' : null\"")
        ->toContain(':aria-label="monthEventLabel(ev)"')
        ->toMatch('/<button\s+v-for="ev in getEventsForDay\(cell\.iso\)/');
});

it('muestra "Cargando…" al cambiar de mes', function () {
    expect(monthMarkup())->toContain('<div v-if="isLoading" class="dwc-month-loading" role="status">Cargando…</div>')
        ->toContain(":aria-busy=\"isLoading ? 'true' : 'false'\"");
});

it('las citas se agrupan por día una sola vez, no en cada celda', function () {
    preg_match('/getEventsForDay\(isoDate\) \{(.*?)\n            \},/s', monthView(), $m);

    expect($m[1])->toContain('return this.eventsByDay.get(isoDate) || [];')
        ->not->toContain('.filter(')
        ->and(monthView())->toContain('eventsByDay() {');
});

it('la celda del mes no hereda el :hover del selector de fechas (borraba el rayado)', function () {
    expect(monthMarkup())->toContain('class="dwc-month-cell"')
        ->not->toContain('ddc-date-day dwc-month-cell');
});
