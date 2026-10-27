<?php

/*
| "Estado de la cita" del modal de detalle (calendario de doctores).
| Va con la misma barra que las etapas de leads (.stage, sin bordes): una
| grilla por breakpoint de PANTALLA (sm:grid-cols-5) metía 5 botones de ~80px
| en la columna de ~440px del modal y el texto se desbordaba.
*/

function doctorDayCalendarView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/components/activities/doctor-day-calendar.blade.php');
}

function statusBarMarkup(): string
{
    $view = doctorDayCalendarView();

    $start = strpos($view, 'Estado de la cita</p>');
    expect($start)->not->toBeFalse('No se encontró el bloque "Estado de la cita"');

    return substr($view, $start, strpos($view, '<!-- Motivo (editable) -->', $start) - $start);
}

it('usa la barra de etapas de leads, sin grilla por breakpoint ni anillos', function () {
    expect(statusBarMarkup())
        ->toContain('ddc-status-bar')
        ->toContain('class="stage ')
        ->toContain('overflow-x-auto')
        ->not->toContain('sm:grid-cols-5')
        ->not->toContain('ring-2');
});

it('ofrece los cinco estados y marca el elegido con color inline', function () {
    $markup = statusBarMarkup();

    foreach (['confirmed', 'completed', 'surgery', 'no_show', 'cancelled'] as $state) {
        expect($markup)->toContain("key: '{$state}'");
    }

    // Colores calculados en runtime: inline, no clases (no están en el CSS de prod).
    expect($markup)->toContain("'--st-color': st.color, backgroundColor: st.color");
});

it('los tamaños de la barra están en el CSS del componente (pl-6/pl-5 no están compiladas)', function () {
    expect(doctorDayCalendarView())
        ->toContain('.ddc-status-bar .stage { flex: 1 0 auto;')
        ->toContain('.ddc-status-bar .stage.is-active::after { background-color: var(--st-color); }');
});

it('al abrir el modal desplaza la barra hasta el estado elegido', function () {
    $view = doctorDayCalendarView();

    expect($view)->toContain('revealApptState() {')
        ->and(substr_count($view, 'this.revealApptState();'))->toBe(2);
});
