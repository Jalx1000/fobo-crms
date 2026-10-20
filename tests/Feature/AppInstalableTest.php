<?php

/**
 * La app instalable de Sensia (PWA): que el celular pueda ofrecer "Instalar app".
 *
 * Lo que Chrome exige para instalar: un manifest con nombre, íconos de 192 y 512,
 * start_url y display standalone, enlazado desde la página. Si alguno falta, el
 * botón no aparece y el distribuidor vuelve a perder el link.
 */
it('el login enlaza el manifest y registra el service worker', function () {
    test()->get(route('admin.session.create'))
        ->assertOk()
        ->assertSee('rel="manifest"', false)
        ->assertSee('manifest.webmanifest', false)
        ->assertSee('serviceWorker', false);
});

it('el manifest tiene lo que Chrome pide para instalar', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
    $tamanos = array_column($manifest['icons'], 'sizes');

    expect($manifest['name'])->not->toBeEmpty()
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toStartWith('/admin/login')
        ->and($tamanos)->toContain('192x192')->toContain('512x512');

    foreach ($manifest['icons'] as $icono) {
        expect(file_exists(public_path(ltrim($icono['src'], '/'))))->toBeTrue();
    }
});

it('el service worker existe y no cachea pantallas del CRM', function () {
    $sw = file_get_contents(public_path('sw.js'));

    expect($sw)->toContain("addEventListener('fetch'")
        ->not->toContain('caches.open');
});
