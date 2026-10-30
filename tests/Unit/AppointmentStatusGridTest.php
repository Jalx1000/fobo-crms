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

it('son chips sueltos (no flechas de pipeline) que pasan de línea en vez de desbordar', function () {
    expect(statusBarMarkup())
        ->toContain('class="ddc-status-chips"')
        ->toContain('class="ddc-status-chip"')
        ->toContain('<span class="ddc-status-dot" aria-hidden="true"></span>')
        ->not->toContain('class="stage ')
        ->not->toContain('sm:grid-cols-5')
        ->and(doctorDayCalendarView())->toContain('.ddc-status-chips { display: flex; flex-wrap: wrap; gap: 6px; width: 100%; }')
        // A todo el ancho: cada chip se estira dentro de su fila.
        ->toContain('display: inline-flex; flex: 1 1 auto; align-items: center; justify-content: center;');
});

it('ofrece los cinco estados y marca el elegido con color inline', function () {
    $markup = statusBarMarkup();
    $view = doctorDayCalendarView();
    $states = substr($view, strpos($view, 'apptStates: ['), 1200);

    // La lista vive en data (apptStates): la comparten la barra, el badge y el historial.
    expect($markup)->toContain('v-for="st in apptStates"');

    foreach (['confirmed', 'completed', 'surgery', 'no_show', 'cancelled'] as $state) {
        expect($states)->toContain("key: '{$state}'");
    }

    // Colores calculados en runtime: inline, no clases (no están en el CSS de prod).
    expect($markup)->toContain(":style=\"{ '--st-color': st.color, '--st-fill': st.fill, '--st-text': st.text }\"");
});

it('el estado elegido va relleno de su pastel con texto oscuro', function () {
    expect(doctorDayCalendarView())
        ->toContain("border-color: var(--st-color); background: var(--st-fill);\n        color: var(--st-text); font-weight: 600; cursor: default;")
        ->toContain('.ddc-status-chip.is-active .ddc-status-dot { background: var(--st-text); }');
});
