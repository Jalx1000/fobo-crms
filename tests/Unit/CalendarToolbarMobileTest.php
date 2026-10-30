<?php

/*
| Cabecera y barra de controles del Calendario: sin bordes grises y ordenadas
| para el celular (fecha → vista → buscador → doctores; acciones a lo ancho).
| Estilos en CSS propio de cada vista: no dependen de clases compiladas.
*/

function calendarViewFile(string $path): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/'.$path);
}

it('la barra de controles no usa botones con borde gris y sigue el orden fecha, vista, buscador, doctores', function () {
    $view = calendarViewFile('components/activities/doctor-day-calendar.blade.php');
    $toolbar = substr($view, strpos($view, '<div class="dwc-controls ddc-toolbar">'), 7000);

    expect($toolbar)->not->toContain('rounded border');

    $order = array_map(fn ($c) => strpos($toolbar, $c), ['ddc-tb-date', 'ddc-seg', 'ddc-tb-search', 'ddc-tb-doctors']);
    expect($order)->each->not->toBeFalse()
        ->and($order)->toBe(collect($order)->sort()->values()->all());
});

it('en el celular los controles van uno por fila y a lo ancho', function () {
    expect(calendarViewFile('components/activities/doctor-day-calendar.blade.php'))
        ->toContain('.ddc-toolbar { display: flex; flex-direction: column; align-items: stretch; border-radius: 0; padding: 10px 12px; }');
});

it('en el celular los filtros van en el panel y la barra no se sale de la pantalla', function () {
    $view = calendarViewFile('components/activities/doctor-day-calendar.blade.php');
    $mobile = substr($view, strpos($view, '/* Celular y tablet: una fila por control, todo a lo ancho. */'), 3000);

    expect($mobile)
        // Ni desplegables apilados (la base flex de 15rem se aplicaba a la altura) ni fila de chips.
        ->toContain('.ddc-tb-doctors { display: none; }')
        // .dwc-controls trae flex-wrap: wrap: en columna, el hijo más ancho ensanchaba toda la barra.
        ->toContain('.ddc-toolbar { flex-wrap: nowrap; }');
});

it('la cabecera en el celular va sin borde y con las acciones en una fila propia', function () {
    $view = calendarViewFile('activities/index.blade.php');

    expect($view)->toContain('class="act-head ')
        ->toContain('margin: -12px -12px 0; padding: 12px; border: 0; border-radius: 0;')
        ->toContain('grid-column: 1 / -1; grid-row: 2;');
});

it('los botones de sincronización no tienen borde y usan textos cortos en el celular', function () {
    $view = calendarViewFile('components/smd-sync-control.blade.php');

    expect($view)->not->toContain('rounded-md border px-2.5')
        ->not->toContain('class="secondary-button')
        ->toContain('<span class="ssc-short">Reanudar</span>')
        ->toContain('<span class="ssc-short">Sincronizar</span>');
});

it('en el celular no queda fondo gris: sin separación y el calendario de borde a borde', function () {
    expect(calendarViewFile('activities/index.blade.php'))
        ->toContain('<div class="act-page flex flex-col gap-4">')
        ->toContain('.act-page { gap: 0; }')
        // Solo con el calendario adentro: las tarjetas de la vista de lista no se tocan.
        ->toContain('.act-page > .act-body:has(.dwc-container) { margin: 0 -12px; }');

    expect(calendarViewFile('components/activities/doctor-day-calendar.blade.php'))
        ->toContain('.dwc-container { gap: 0; }')
        ->toContain('.dwc-container .dwc-grid { border-left: 0; border-right: 0; border-radius: 0; }');
});

it('en escritorio la fecha y la vista van arriba, el buscador y los filtros debajo', function () {
    expect(calendarViewFile('components/activities/doctor-day-calendar.blade.php'))
        // Dos filas en todo escritorio: con tres filtros no entra todo en una.
        ->toContain('grid-template-areas: "date . seg" "search doctors doctors";')
        ->not->toContain('grid-template-areas: "date search doctors seg";')
        ->toContain('.ddc-tb-search { grid-area: search; justify-self: start; width: 100%; }')
        // En el celular los filtros van en una fila deslizable.
        ->not->toContain('.ddc-tb-doctors { flex-direction: column; align-items: stretch; gap: 6px; }');
});

it('en escritorio las tres acciones de la cabecera van pegadas al selector lista/calendario', function () {
    $view = calendarViewFile('activities/index.blade.php');

    expect($view)->toContain('<div class="act-head-right">')
        ->toContain('.act-head .act-head-right { display: flex; align-items: center; gap: 8px; }')
        // En el celular el contenedor se disuelve y vale la grilla de dos filas.
        ->toContain('.act-head .act-head-right { display: contents; }');

    // Acciones y vista dentro del mismo contenedor.
    $right = substr($view, strpos($view, '<div class="act-head-right">'));
    expect(strpos($right, 'act-head-actions'))->toBeLessThan(strpos($right, 'act-head-views'));
});

it('en escritorio los controles usan el estilo del admin; el relleno gris sin borde es solo del celular', function () {
    $calendar = calendarViewFile('components/activities/doctor-day-calendar.blade.php');
    $desktop = substr($calendar, strpos($calendar, '/* Escritorio: estilo del admin'));

    expect($desktop)->toContain('@media (min-width: 1024px)')
        ->toContain('border: 1px solid #e5e7eb; border-radius: 6px; background: #fff;')
        ->toContain('.ddc-seg-btn + .ddc-seg-btn { border-left: 1px solid #e5e7eb; }');

    $sync = calendarViewFile('components/smd-sync-control.blade.php');
    expect(substr($sync, strpos($sync, '/* Escritorio: como los botones del admin')))
        ->toContain('@media (min-width: 1024px)')
        ->toContain('.ssc-btn { height: 36px; border: 1px solid; border-radius: 6px; background: #fff; }');
});
