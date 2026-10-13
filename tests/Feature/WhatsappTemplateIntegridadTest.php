<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Que los x-template de la página compilen.
 *
 * Un `<div>` sin cerrar dentro de un `<script type="text/x-template">` no rompe
 * nada del lado del servidor: la página sigue devolviendo 200 y el HTML sale
 * completo. Lo que se rompe es el compilador de Vue en el navegador
 * (X_MISSING_END_TAG, error 24), que aborta el componente ENTERO y deja el panel
 * en blanco sin un solo mensaje en el log de Laravel.
 *
 * Pasó de verdad: al quitar una rama duplicada del `v-if` de tipos de mensaje
 * quedó su `</div>` huérfano, y la bandeja se abría en blanco. Los tests que ya
 * había miraban el contenido —que existiera cada rama, que no hubiera llaves de
 * Blade— pero ninguno miraba la ESTRUCTURA, que es lo único que el navegador no
 * perdona.
 *
 * Se verifica sobre el HTML renderizado y no sobre el .blade: lo que el navegador
 * compila es la salida, con los componentes de Blade ya expandidos.
 */
uses(DatabaseTransactions::class);

/**
 * El primer desbalance de etiquetas, o null si está bien.
 *
 * No usa un parser de HTML a propósito: DOMDocument repara los errores en
 * silencio, que es exactamente lo que hay que detectar.
 */
function desbalanceDe(string $html): ?string
{
    // Vacías por definición: nunca llevan cierre. `path` y compañía están porque
    // el componente dibuja iconos SVG inline.
    $vacias = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta',
        'param', 'source', 'track', 'wbr',
        'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'use', 'stop',
    ];

    // Los comentarios pueden contener ejemplos de etiquetas.
    $limpio = preg_replace('/<!--.*?-->/s', '', $html);

    preg_match_all(
        '/<(\/?)([a-zA-Z][-a-zA-Z0-9:._]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(\/?)>/',
        $limpio,
        $etiquetas,
        PREG_SET_ORDER
    );

    $pila = [];

    foreach ($etiquetas as $t) {
        $esCierre = $t[1] === '/';
        $nombre = strtolower($t[2]);
        $autocierre = $t[4] === '/';

        if ($autocierre || in_array($nombre, $vacias, true)) {
            continue;
        }

        if (! $esCierre) {
            $pila[] = $nombre;

            continue;
        }

        if ($pila && end($pila) === $nombre) {
            array_pop($pila);

            continue;
        }

        return "</{$nombre}> no cierra lo que está abierto (arriba: ".(end($pila) ?: 'nada').')';
    }

    return $pila ? 'quedaron sin cerrar: '.implode(', ', $pila) : null;
}

/** @return array<string, string> id del template => contenido */
function templatesDe(string $html): array
{
    preg_match_all(
        '/<script[^>]*type="text\/x-template"[^>]*id="([^"]+)"[^>]*>(.*?)<\/script>/s',
        $html,
        $m,
        PREG_SET_ORDER
    );

    return collect($m)->mapWithKeys(fn ($t) => [$t[1] => $t[2]])->all();
}

it('todos los x-template de la bandeja cierran sus etiquetas', function () {
    $html = $this->actingAs(getDefaultAdmin(), 'user')
        ->get(route('admin.whatsapp.index'))
        ->assertOk()
        ->getContent();

    $templates = templatesDe($html);

    expect($templates)->toHaveKey('v-whatsapp-inbox-template');

    foreach ($templates as $id => $cuerpo) {
        expect(desbalanceDe($cuerpo))->toBeNull("El template {$id} no compila: ");
    }
});

it('cada tipo de mensaje abre al mismo nivel que sus hermanos', function () {
    /*
     * La cadena v-if / v-else-if de tipos de mensaje son HERMANOS: todos tienen
     * que abrir a la misma profundidad. Cuando uno queda a otro nivel, Vue lo
     * trata como hijo del anterior y el v-else deja de ser alcanzable —el
     * síntoma es más sutil que el blanco: la rama simplemente no se muestra
     * nunca.
     *
     * No reemplaza al chequeo de balance: el `</div>` huérfano que dejó la
     * bandeja en blanco lo agarra ese y no éste, porque caía sobre el v-else
     * final —que no nombra ningún msg.type— y no sobre una rama con nombre. Este
     * cubre el caso distinto de una rama que se anida dentro de su hermana
     * anterior, que no desbalancea nada y por eso ningún otro test ve.
     */
    $html = $this->actingAs(getDefaultAdmin(), 'user')
        ->get(route('admin.whatsapp.index'))
        ->assertOk()
        ->getContent();

    $cuerpo = templatesDe($html)['v-whatsapp-inbox-template'] ?? '';

    expect($cuerpo)->not->toBe('');

    $vacias = ['br', 'img', 'input', 'hr', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'use', 'stop', 'source', 'track', 'meta', 'link'];
    $limpio = preg_replace('/<!--.*?-->/s', '', $cuerpo);

    preg_match_all(
        '/<(\/?)([a-zA-Z][-a-zA-Z0-9:._]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(\/?)>/',
        $limpio,
        $etiquetas,
        PREG_SET_ORDER
    );

    $profundidad = 0;
    $niveles = [];

    foreach ($etiquetas as $t) {
        $esCierre = $t[1] === '/';
        $nombre = strtolower($t[2]);

        if ($t[4] === '/' || in_array($nombre, $vacias, true)) {
            continue;
        }

        if ($esCierre) {
            $profundidad--;

            continue;
        }

        $profundidad++;

        if (preg_match('/v-(?:else-)?if="msg\.type === \'([a-z]+)\'"/', $t[3], $rama)) {
            $niveles[$rama[1]] = $profundidad;
        }
    }

    // Las ramas que el módulo sabe mostrar. Si mañana se agrega una, entra sola.
    expect(count($niveles))->toBeGreaterThanOrEqual(10)
        ->and(array_unique(array_values($niveles)))->toHaveCount(
            1,
            'Hay ramas de tipo de mensaje a distinta profundidad: '.json_encode($niveles)
        );
});
