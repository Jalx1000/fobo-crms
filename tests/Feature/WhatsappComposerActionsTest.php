<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/**
 * El selector de acciones del composer.
 *
 * Antes de esto el menú existía como maqueta: llamaba a `mockAttach()`, que
 * empujaba un mensaje falso al array local y no enviaba nada. Estos casos
 * verifican que la página real trae el menú cableado a los endpoints, y que las
 * opciones salen de las capacidades del gateway y no están escritas a mano.
 */
uses(DatabaseTransactions::class);

function usuarioAdmin(): User
{
    $id = (int) DB::table('users')->insertGetId([
        'name'       => 'Test Composer '.uniqid(),
        'email'      => 'composer-'.uniqid().'@test.local',
        'password'   => bcrypt('password'),
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::find($id);
}

it('la página del inbox carga y trae las cuatro urls del selector', function () {
    $response = test()->actingAs(usuarioAdmin(), 'user')
        ->get(route('admin.whatsapp.index'));

    $response->assertStatus(200)
        ->assertSee('send-media-url', false)
        ->assertSee('send-location-url', false)
        ->assertSee('templates-url', false)
        ->assertSee('send-template-url', false);
});

it('el menú ofrece adjuntos, ubicación y plantilla', function () {
    $response = test()->actingAs(usuarioAdmin(), 'user')
        ->get(route('admin.whatsapp.index'));

    $response->assertSee('Adjuntar archivo, ubicación o plantilla', false)
        ->assertSee('Ubicación', false)
        ->assertSee('Plantilla', false)
        // La salida del callejón sin salida de la ventana de 24 h.
        ->assertSee('Elegir plantilla', false);
});

it('ya no queda nada de la maqueta que no enviaba', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    expect($blade)->not->toContain('mockAttach')
        // icon-map no existe en el CSS de este proyecto; icon-location sí.
        ->and($blade)->not->toContain('icon-map');
});

it('el gateway activo declara las capacidades que el menú va a dibujar', function () {
    $capabilities = app(\Webkul\Whatsapp\Gateways\GatewayManager::class)
        ->active()
        ->capabilities();

    foreach (['image', 'document', 'audio', 'video', 'location', 'template'] as $kind) {
        expect($capabilities->canSend($kind))->toBeTrue("Falta la capacidad {$kind}");
    }
});

it('un canal sin capacidades no dibuja opciones que fallarían', function () {
    config()->set('whatsapp.gateway', 'kommo');

    $capabilities = app(\Webkul\Whatsapp\Gateways\GatewayManager::class)
        ->active()
        ->capabilities();

    // Kommo solo relaya texto: el menú entero no debe aparecer.
    foreach (['image', 'document', 'audio', 'video', 'location', 'template'] as $kind) {
        expect($capabilities->canSend($kind))->toBeFalse("Kommo no debería declarar {$kind}");
    }
});

/*
|--------------------------------------------------------------------------
| Regresión: llaves dobles literales en el blade del inbox
|--------------------------------------------------------------------------
| El componente es un `x-template` dentro de un archivo Blade, así que dos
| llaves seguidas son sintaxis de Blade Y de Vue, y romperlo no da ningún error
| visible: la página sigue devolviendo 200 y el componente queda en blanco.
|
| Pasó dos veces el mismo día:
|   - en el HTML, `@{{ '{{' + n + '}}' }}`: Vue corta la interpolación en el
|     primer cierre y queda un string sin terminar -> no compila el template;
|   - en el <script>, la misma constante: Blade la reemplaza por un
|     `<?php echo ?>` dentro del JavaScript.
|
| Por eso el marcador se arma concatenando, en `placeholder()`.
*/

it('no tiene llaves dobles literales fuera de @ y de v-pre', function () {
    $ruta = base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php');

    $lineas = file($ruta, FILE_IGNORE_NEW_LINES);
    $ofensivas = [];

    foreach ($lineas as $i => $linea) {
        // `v-pre` le dice a Vue que no interprete ese nodo: ahí las llaves
        // literales son intencionales (se documentan las variables al usuario).
        if (str_contains($linea, 'v-pre')) {
            continue;
        }

        // Se quitan las interpolaciones escapadas y bien formadas, que son las
        // legítimas; lo que sobre con llaves dobles es el problema.
        $resto = preg_replace('/@\{\{(?:(?!\}\}).)*\}\}/s', '', $linea);

        /*
         * Y se quita también un echo de Blade sobre una variable PHP
         * —`{{ $waMoneda }}`—, que Blade compila a `<?php echo e(...) ?>` antes
         * de que el HTML salga del servidor: esas llaves nunca llegan a Vue.
         *
         * El patrón es deliberadamente angosto (una variable, con accesos y una
         * llamada opcional) y no un `{{ cualquier cosa }}`: el bug que este
         * test cuida era `@{{ '{{' + n + '}}' }}`, o sea llaves literales
         * DENTRO de un string de JavaScript. Aflojar esto a todo lo que tenga
         * llaves lo dejaría pasar de nuevo.
         */
        $resto = preg_replace('/\{\{\s*\$[A-Za-z_][A-Za-z0-9_]*(?:(?:->|::)[A-Za-z_][A-Za-z0-9_]*(?:\([^()\'"]*\))?|\[[^\[\]\'"]*\])*\s*\}\}/', '', $resto);

        if (str_contains($resto, '{{') || str_contains($resto, '}}')) {
            $ofensivas[] = ($i + 1).': '.trim($linea);
        }
    }

    expect($ofensivas)->toBe([], "Llaves dobles literales en:\n".implode("\n", $ofensivas));
});

it('no deja php ejecutado dentro del javascript del componente', function () {
    $html = test()->actingAs(usuarioAdmin(), 'user')
        ->get(route('admin.whatsapp.index'))
        ->getContent();

    // Artefacto exacto que dejaba el bug: Blade compilaba la constante del
    // marcador y el `e()` escapaba las comillas de la expresión JS.
    expect($html)->not->toContain('&#039; + (i + 1) + &#039;')
        // Y el helper que lo reemplaza tiene que estar presente.
        ->and($html)->toContain('placeholder(n)');
});

/**
 * Todos los iconos referenciados existen en la fuente del proyecto.
 *
 * Una clase `icon-*` inexistente no rompe nada visible en los tests ni en la
 * consola: simplemente no se dibuja nada y queda un hueco. Ya había tres en el
 * archivo — `icon-map` en la burbuja de ubicación y `icon-microphone` en el menú
 * de adjuntos, ambos de la maqueta original, más uno que agregué yo.
 */
it('no referencia iconos que no existen en el css', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    $css = file_get_contents(
        base_path('packages/Webkul/Admin/src/Resources/assets/css/app.css')
    );

    preg_match_all('/icon-[a-z-]+/', $blade, $matches);

    $faltantes = collect($matches[0])
        ->unique()
        ->reject(fn ($icon) => str_contains($css, '.'.$icon))
        ->values()
        ->all();

    expect($faltantes)->toBe([], 'Iconos inexistentes: '.implode(', ', $faltantes));
});

it('el perfil del cliente incluye audio y video, no solo imagen y documento', function () {
    $controller = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Http/Controllers/ChatController.php')
    );

    expect($controller)->toContain("['image', 'document', 'audio', 'video']");
});

/**
 * Visor de imágenes portado de imprimir: zoom, rotación, paneo y navegación.
 *
 * Su CSS es propio, no clases de Tailwind, y esa decisión hay que preservarla:
 * el build del admin está precompilado, así que una utilidad nueva escrita en
 * este blade no se compilaría y el visor saldría sin estilos.
 */
it('trae el visor de imágenes con su css propio', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    // Las clases del visor están definidas en el <style> del propio archivo.
    foreach (['.wa-viewer', '.wa-toolbar', '.wa-nav', '.wa-counter', '.wa-caption', '.wa-zoomable'] as $clase) {
        expect($blade)->toContain($clase);
    }

    // Y los controles existen.
    foreach (['viewerZoom', 'viewerRotate', 'viewerReset', 'viewerPrev', 'viewerNext', 'viewerDragStart'] as $metodo) {
        expect($blade)->toContain($metodo);
    }
});

it('abre el visor desde el hilo y desde la galería del perfil', function () {
    $blade = file_get_contents(
        base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php')
    );

    // Desde el hilo, con todas las imágenes del chat para navegar.
    expect($blade)->toContain('openLightbox(msg.media.url, chatImageUrls())')
        // Desde la galería, sin los videos: el visor no los maneja.
        ->and($blade)->toContain('openLightbox(img.url, profileImageUrls())')
        ->and($blade)->toContain("filter(x => x.type !== 'video')");
});

/**
 * Toda burbuja con archivo tiene que estar guardada por `msg.media`.
 *
 * Un mensaje multimedia puede existir SIN archivo: la referencia de Meta caduca,
 * y hasta que existió la descarga automática ninguna media entrante se guardaba.
 * En ese caso `transform()` devuelve `media: null`.
 *
 * Sin la guarda, `msg.media.url` sobre null lanza un TypeError DURANTE EL RENDER
 * de Vue, y eso deja la conversación entera en blanco — no solo esa burbuja. Fue
 * exactamente el bug de "no me abre el chat", reproducido en un navegador real:
 *
 *   TypeError: Cannot read properties of null (reading 'url') at Proxy.render
 */
it('ninguna burbuja accede al archivo sin verificar que exista', function () {
    $ruta = base_path('packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php');

    $sinGuarda = [];

    foreach (file($ruta, FILE_IGNORE_NEW_LINES) as $i => $linea) {
        // Comentarios fuera: documentan justamente este problema.
        if (str_contains($linea, '`msg.media.url`')) {
            continue;
        }

        if (! preg_match('/msg\.media\.\w/', $linea)) {
            continue;
        }

        $guardado = str_contains($linea, 'v-if="msg.media"')
            || str_contains($linea, 'msg.media ?')
            || str_contains($linea, 'msg.media &&')
            // Un @click sobre un elemento que ya lleva su propia v-if solo se
            // evalúa cuando ese elemento existe.
            || str_contains($linea, '@click=');

        if (! $guardado) {
            $sinGuarda[] = ($i + 1).': '.trim($linea);
        }
    }

    expect($sinGuarda)->toBe([], "Acceso sin guarda a msg.media:\n".implode("\n", $sinGuarda));
});
