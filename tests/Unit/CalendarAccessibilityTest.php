<?php

/*
| Accesibilidad de los controles del calendario (WCAG 2.1 AA): foco visible con
| teclado, roles ARIA correctos y contraste de texto ≥ 4.5:1.
*/

function a11yView(string $path): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/'.$path);
}

/** Contraste WCAG entre dos colores hex. */
function a11yContrast(string $a, string $b): float
{
    $lum = function (string $hex): float {
        [$r, $g, $b] = array_map(fn ($i) => hexdec(substr(ltrim($hex, '#'), $i, 2)) / 255, [0, 2, 4]);
        $f = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    };

    [$hi, $lo] = [max($lum($a), $lum($b)), min($lum($a), $lum($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}

it('el texto de cada estado tiene contraste AA sobre su relleno pastel', function () {
    $view = a11yView('components/activities/doctor-day-calendar.blade.php');
    $states = substr($view, strpos($view, 'apptStates: ['), 1400);
    preg_match_all("/key: '(\\w+)',\\s+label: '[^']+',\\s+color: '#[0-9a-f]{6}',\\s+fill: '(#[0-9a-f]{6})',\\s+text: '(#[0-9a-f]{6})'/i", $states, $m, PREG_SET_ORDER);

    expect($m)->toHaveCount(5);

    // El chip elegido y el badge pintan el pastel (fill) con el texto (text).
    expect($view)->toContain('background: var(--st-fill, #e5e7eb); color: var(--st-text, #374151);')
        ->toContain("border-color: var(--st-color); background: var(--st-fill);\n        color: var(--st-text);");

    foreach ($m as [, $state, $fill, $text]) {
        expect(a11yContrast($text, $fill))->toBeGreaterThanOrEqual(4.5, "Estado {$state}: contraste insuficiente de {$text} sobre {$fill}");
    }
});

it('la vista Día/Semana/Mes/Lista y el estado de la cita son grupos de botones con aria-pressed (no pestañas sin flechas)', function () {
    $view = a11yView('components/activities/doctor-day-calendar.blade.php');

    expect($view)->not->toContain('role="tablist"')
        ->not->toContain('role="tab"')
        ->toContain('<div class="ddc-seg" role="group" aria-label="Vista del calendario">')
        ->toContain(':aria-pressed="viewType === v.key"')
        ->toContain('role="group" aria-label="Estado de la cita"');
});

it('los controles muestran el foco con teclado y el buscador no lo elimina', function () {
    $view = a11yView('components/activities/doctor-day-calendar.blade.php');

    expect($view)->toContain('.ddc-toolbar button:focus-visible, .ddc-status-chip:focus-visible {')
        ->not->toContain('.ddc-tb-search input:focus { outline: 0;');

    expect(a11yView('components/smd-sync-control.blade.php'))
        ->toContain('.ssc-btn:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }');
});

it('a 320px las tres acciones de la cabecera pueden achicarse sin salirse de la pantalla', function () {
    expect(a11yView('activities/index.blade.php'))
        ->toContain('grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);')
        ->toContain('@media (max-width: 359px)');

    expect(a11yView('components/smd-sync-control.blade.php'))
        ->toContain('.ssc-wrap .ssc-btn { flex: 1 1 0; min-width: 0; }');
});
