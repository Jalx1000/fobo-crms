<?php

/**
 * Que las clases del dropzone existan en el CSS compilado.
 *
 * El bug que esto previene, y que ya ocurrió: el markup tenía
 * `border-2 border-dashed`, pero el CSS del admin estaba compilado de ANTES de
 * que existiera este archivo. `border-dashed` sobrevivía porque otra vista ya la
 * usaba; `border-2` no la usaba nadie más y quedó fuera del bundle.
 *
 * Resultado: `border-style: dashed` sin `border-width` → borde de 0 px. El
 * markup era correcto, las clases estaban en el HTML, y aun así no se veía nada.
 *
 * Tailwind hace tree-shaking contra los archivos fuente: una clase que solo
 * aparece en un blade nuevo NO existe hasta que se corre `npm run build` dentro
 * de packages/Webkul/Admin. Este test falla cuando alguien agrega una utilidad
 * nueva y se olvida de recompilar.
 */
function cssDelAdmin(): string
{
    $manifest = public_path('admin/build/manifest.json');

    expect(file_exists($manifest))->toBeTrue();

    $entradas = json_decode(file_get_contents($manifest), true);

    $css = collect($entradas)
        ->pluck('file')
        ->first(fn ($f) => str_ends_with((string) $f, '.css'));

    expect($css)->not->toBeNull();

    return file_get_contents(public_path('admin/build/'.$css));
}

it('el CSS compilado define el borde punteado del dropzone', function () {
    $css = cssDelAdmin();

    // Las tres juntas: sin ancho el estilo no dibuja nada, y sin color se ve el
    // borde del navegador en vez del del diseño.
    expect($css)->toContain('.border-2{border-width:2px}')
        ->and($css)->toContain('.border-dashed{border-style:dashed}')
        ->and($css)->toContain('.border-gray-300{');
});

it('el CSS compilado trae el resto de las utilidades del dropzone', function () {
    $css = cssDelAdmin();

    // Ojo: toContain() en Pest es variádica - un segundo argumento se toma como
    // otra aguja a buscar, no como mensaje. El detalle va en el nombre del test.
    foreach (['.sr-only{', '.cursor-pointer{', '.rounded-lg{', '.border-dashed{'] as $clase) {
        expect($css)->toContain($clase);
    }
});

it('toda utilidad del dropzone está en el bundle', function () {
    /*
     * Barrido genérico: lee las clases que el blade usa de verdad y verifica que
     * cada una exista en el CSS. Así el test no se queda viejo cuando alguien
     * cambia el diseño del dropzone.
     *
     * Se saltean las variantes (hover:, dark:) porque Tailwind las emite con el
     * prefijo escapado y comparar eso acá sería frágil.
     */
    $blade = file_get_contents(base_path(
        'packages/Webkul/Admin/src/Resources/views/products/_dropzone.blade.php'
    ));

    preg_match('/<label\s[^>]*class="([^"]+)"/s', $blade, $m);

    expect($m)->not->toBeEmpty();

    $css = cssDelAdmin();

    $faltantes = collect(preg_split('/\s+/', $m[1]))
        ->filter()
        ->reject(fn ($c) => str_contains($c, ':'))
        ->reject(fn ($c) => $css && str_contains($css, '.'.$c.'{'))
        ->values()
        ->all();

    expect($faltantes)->toBe([]);
});
