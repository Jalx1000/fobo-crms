<?php

/*
| Tablero (vistas Día y Semana): cada hora se divide en slots de 15 min, al pasar
| el cursor por un horario disponible se ve la hora exacta en que se agendaría
| (p. ej. «14:15»), y el horario no disponible va rayado. El click tiene que usar
| el mismo redondeo que el hover: antes agendaba al minuto exacto (14:07).
*/

function hoverSlotView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/components/activities/doctor-day-calendar.blade.php');
}

it('las vistas Día y Semana muestran el slot bajo el cursor', function () {
    $view = hoverSlotView();

    expect(substr_count($view, '@mousemove="onDayHover($event, day,'))->toBe(2)
        ->and(substr_count($view, '@mouseleave="hoverSlot = null"'))->toBe(2)
        ->and(substr_count($view, 'class="dwc-hover-slot"'))->toBe(2)
        ->and(substr_count($view, 'class="dwc-quarter-lines"'))->toBe(2);
});

it('la cuadrícula es de 15 minutos', function () {
    expect(hoverSlotView())->toContain('slotMinutes: 15,');
});

it('el hover y el click redondean la hora con la misma función', function () {
    $view = hoverSlotView();

    preg_match('/onDayClick\(e, day, doctorId\) \{(.*?)\n            \},/s', $view, $click);
    preg_match('/onDayHover\(e, day, doctorId\) \{(.*?)\n            \},/s', $view, $hover);

    expect($click[1])->toContain('this.slotMinutesAt(e)')
        ->toContain('this.isDoctorAvailableAt(day.date, doctorId, minutes)')
        ->not->toContain('Math.round(yLocal')
        ->and($hover[1])->toContain('this.slotMinutesAt(e)')
        ->toContain('this.isDoctorAvailableAt(day.date, doctorId, minutes)');
});

it('el slot redondea hacia abajo al paso de la cuadrícula', function () {
    expect(hoverSlotView())
        ->toContain('const snapped = Math.floor(raw / this.slotMinutes) * this.slotMinutes;');
});

it('el horario no disponible va rayado y no invita a hacer click', function () {
    $view = hoverSlotView();

    expect($view)
        ->toMatch('/\.dwc-days-stack \{[^}]*repeating-linear-gradient\(-45deg/')
        ->toMatch('/\.dwc-day-block \{[^}]*cursor: not-allowed;/')
        ->toMatch('/\.dwc-availability-block \{[^}]*cursor: pointer;/');
});
