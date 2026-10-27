<?php

/*
| Los estilos de la cabecera del Calendario tienen que llegar al navegador.
| Un <style> dentro del contenido de la página queda dentro de #app, y Vue lo
| descarta al montar: la cabecera se veía sin su disposición (hubo que agregar
| "flex" a mano en el navegador). Van en la pila "styles", dentro del <head>.
*/

use Webkul\User\Models\User;

it('los estilos de la cabecera del calendario se imprimen en el <head>, fuera de #app', function () {
    $html = $this->actingAs(User::find(1), 'user')
        ->get(route('admin.activities.index'))
        ->assertOk()
        ->getContent();

    $style = strpos($html, '.act-head .act-head-right { display: flex;');
    $head = strpos($html, '</head>');
    $app = strpos($html, 'id="app"');

    expect($style)->not->toBeFalse('La regla de la cabecera no está en la página')
        ->and($style)->toBeLessThan($head, 'La regla quedó fuera del <head>')
        ->and($style)->toBeLessThan($app, 'La regla quedó dentro de #app: Vue la descarta');
});

it('la cabecera sigue teniendo las acciones y el selector de vista juntos', function () {
    $html = $this->actingAs(User::find(1), 'user')
        ->get(route('admin.activities.index'))
        ->getContent();

    expect($html)->toContain('<div class="act-head-right">');
});
