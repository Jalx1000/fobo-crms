<?php

/*
| Vista Día del calendario: la columna de horas no tiene encabezado y asume que
| todos los encabezados de doctor miden una línea. Un nombre largo en dos líneas
| ("DIANA VALERIA HURTADO OYOLA") bajaba toda su columna y las citas quedaban
| desfasadas de la hora. El encabezado va en una línea, con el nombre cortado y
| completo en el title.
*/

dataset('calendarios', [
    'doctor-day-calendar'  => ['components/activities/doctor-day-calendar.blade.php'],
    'doctor-week-calendar' => ['components/activities/doctor-week-calendar.blade.php'],
]);

function doctorHeaderView(string $path): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/'.$path);
}

it('el encabezado de doctor no puede partirse en dos líneas', function (string $path) {
    expect(doctorHeaderView($path))
        ->toContain('.dwc-doctor-header { gap: 6px; white-space: nowrap; }')
        ->toContain('.dwc-doctor-header-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; }')
        ->toContain('.dwc-doctor-header-count { flex-shrink: 0; }');
})->with('calendarios');

it('el nombre cortado se puede leer completo al pasar el mouse', function (string $path) {
    $view = doctorHeaderView($path);

    expect($view)->toMatch('/<div class="dwc-doctor-header" :title="col[^"]*name[^"]*">\s*<span class="dwc-doctor-header-name">/');
})->with('calendarios');
