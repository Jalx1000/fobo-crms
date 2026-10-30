<?php

/*
| Vista Lista: tenía su propio filtro Desde/Hasta (30 días por defecto) aparte de
| la fecha de la barra, que ya manejan todas las vistas. Ahora la lista muestra la
| semana (lunes a domingo) de la fecha de la barra y las flechas avanzan de a semana.
*/

function listView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/components/activities/doctor-day-calendar.blade.php');
}

function listMarkup(): string
{
    $view = listView();
    $start = strpos($view, '<div v-else-if="viewType === \'list\'" class="dwc-list-view">');

    return substr($view, $start, strpos($view, '<div v-else-if="viewType === \'week\'', $start) - $start);
}

it('la lista no tiene un filtro de fechas propio', function () {
    $view = listView();

    expect(listMarkup())->not->toContain('type="date"')
        ->not->toContain('Desde')
        ->not->toContain('Aplicar')
        ->and($view)->not->toContain('applyRange')
        ->not->toContain('.dwc-list-filters');
});

it('el rango de la lista es la semana de la fecha de la barra', function () {
    expect(listView())
        ->toMatch('/rangeStart\(\) \{\s*const d = this\.parseISODate\(this\.startISO\);\s*d\.setDate\(d\.getDate\(\) - \(\(d\.getDay\(\) \+ 6\) % 7\)\);/')
        ->toMatch('/rangeEnd\(\) \{\s*const d = this\.parseISODate\(this\.rangeStart\);\s*d\.setDate\(d\.getDate\(\) \+ 6\);/')
        // Ya no son datos guardados en la cookie: salen de la fecha.
        ->not->toContain('rangeStart: prefs.rangeStart')
        ->not->toContain('rangeStart: this.rangeStart,');
});

it('las flechas mueven la lista de a una semana', function () {
    expect(substr_count(listView(), "if (this.viewType === 'week' || this.viewType === 'list') d.setDate(d.getDate()"))->toBe(2);
});

it('la barra muestra la semana de la lista y la cantidad de citas', function () {
    expect(listView())->toContain('return `${this.formatDate(this.parseISODate(this.rangeStart))} - ${this.formatDate(this.parseISODate(this.rangeEnd))}`;')
        ->and(listMarkup())->toContain('@{{ citasLabel(listAppointments.length) }} esta semana')
        ->toContain('No hay citas en esta semana.');
});

it('el backend sigue recibiendo el rango de la lista', function () {
    expect(substr_count(listView(), 'range_start: this.rangeStart,'))->toBe(2);
});
