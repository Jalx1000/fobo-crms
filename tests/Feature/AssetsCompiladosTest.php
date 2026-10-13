<?php

use Illuminate\Support\Facades\File;

/*
 * El CSS compilado tiene que contener las clases que usan las vistas.
 *
 * Esto existe porque el modo de fallar es SILENCIOSO. El Dockerfile no tiene
 * etapa de Node, así que nadie compila el admin en el deploy: el 06/10/2026 el
 * CSS de producción era del día anterior y no tenía `bg-emerald-700`, así que
 * los botones del Punto se veían grises. No hubo ningún error en ningún log —
 * una clase que Tailwind no generó simplemente no existe, y el navegador la
 * ignora.
 *
 * Si este test falla, el build está viejo:
 *
 *     cd packages/Webkul/Admin && npm run build
 */
function cssCompilado(): string
{
    $manifest = base_path('public/admin/build/manifest.json');

    expect(File::exists($manifest))->toBeTrue(
        'No hay build del admin. Correr: cd packages/Webkul/Admin && npm run build'
    );

    $entradas = json_decode(File::get($manifest), true);
    $archivo = $entradas['src/Resources/assets/css/app.css']['file'] ?? null;

    expect($archivo)->not->toBeNull('El manifest no declara el CSS del admin.');

    return File::get(base_path('public/admin/build/'.$archivo));
}

/**
 * Las clases estáticas de una vista, tal como Tailwind las escribe en el CSS.
 *
 * Sólo los `class="…"` literales: un `:class` de Vue se arma en runtime y
 * Tailwind no lo puede ver, así que pedirle que lo genere no tendría sentido.
 */
function clasesEstaticasDe(string $vista): array
{
    $contenido = File::get($vista);

    preg_match_all('/(?<![:\w])class="([^"{}]*)"/', $contenido, $m);

    $clases = collect($m[1])
        ->flatMap(fn ($lista) => preg_split('/\s+/', trim($lista)))
        ->filter()
        // Las que llevan color o medida son las que importan acá: si falta una,
        // el elemento se ve roto. Un `flex` ausente sería igual de grave, pero
        // estas son las que se agregan a mano y se olvidan.
        ->filter(fn ($c) => (bool) preg_match(
            '/^(dark:)?(hover:|focus:|focus-visible:|group-hover:|disabled:|sm:|md:|lg:)*'
            .'(bg|text|border|ring|min-h|max-h|h|w|z)-/',
            $c
        ))
        ->unique()
        ->values();

    return $clases->all();
}

/** Tailwind escapa `:`, `[`, `]`, `/` y `.` en los selectores. */
function selectorDe(string $clase): string
{
    return '.'.preg_replace('/([:\[\]\/.%])/', '\\\\$1', $clase);
}

it('el CSS compilado tiene las clases del Punto del distribuidor', function () {
    $css = cssCompilado();

    $faltantes = collect(clasesEstaticasDe(
        base_path('packages/Webkul/Distribuidor/src/Resources/views/punto.blade.php')
    ))->reject(fn ($c) => str_contains($css, selectorDe($c)))->values();

    expect($faltantes->all())->toBe(
        [],
        'Clases que el CSS compilado no tiene: '.$faltantes->implode(', ')
            ."\nEl build está viejo. Correr: cd packages/Webkul/Admin && npm run build"
    );
});

/*
 * El verde de los botones primarios, explícito: es el que se rompió, y lo que el
 * usuario reportó fue "los botones no se ven como los verdes".
 */
it('el verde de los botones primarios está compilado', function () {
    $css = cssCompilado();

    // Se junta la lista y se compara UNA vez: un expect()->toContain() sobre el
    // CSS imprime los 100 KB del archivo cuando falla.
    $faltantes = collect(['bg-emerald-700', 'border-emerald-300', 'text-emerald-800'])
        ->reject(fn ($c) => str_contains($css, selectorDe($c)))
        ->values();

    expect($faltantes->all())->toBe([], 'Verdes sin compilar: '.$faltantes->implode(', '));
});
