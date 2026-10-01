<?php

use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;

/**
 * El agente mandando el mismo menú dos veces.
 *
 * Observado en producción (conversación 3, mensajes 1055/1056, 1058/1059,
 * 1063/1064, 1066/1067): el modelo llama a `mostrar_opciones` y ADEMÁS escribe
 * la misma pregunta como texto con las opciones al final. El cliente la recibe
 * dos veces —una con botones y otra sin— y la segunda lo invita a tipear lo que
 * los botones ya resolvían.
 */
beforeEach(function () {
    actingAsSanctumAuthenticatedAdmin();

    $this->conv = Conversation::create([
        'gateway'         => 'cloud_api',
        'wa_phone'        => '59177000'.rand(100, 999),
        'wa_name'         => 'Antidup',
        'ai_enabled'      => true,
        'last_inbound_at' => now(),
    ]);
});

afterEach(function () {
    if (! isset($this->conv)) {
        return;
    }

    Message::where('conversation_id', $this->conv->id)->delete();
    Conversation::whereKey($this->conv->id)->delete();
});

/**
 * Un menú interactivo ya enviado.
 *
 * NO se llama `menu()`: Krayin define un helper global con ese nombre y el
 * layout del admin lo usa. Una función de test con el mismo nombre lo pisa y
 * todas las vistas del admin revientan con 500 — en OTROS archivos de test, que
 * es donde nadie lo va a buscar.
 */
function menuInteractivo($conv, string $body): Message
{
    return Message::create([
        'conversation_id' => $conv->id,
        'direction'       => 'outbound',
        'type'            => 'interactive',
        'body'            => $body,
        'status'          => 'sent',
        'sender'          => 'ia',
        'payload'         => ['interactive' => ['format' => 'list', 'sections' => []]],
    ]);
}

it('rechaza el texto que repite el menú recién enviado', function () {
    $m = menuInteractivo($this->conv, '¿De qué ciudad nos escribe?');

    $r = test()->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/messages", [
        'text' => "¿De qué ciudad nos escribe?\n▸ Santa Cruz\n▸ La Paz\n▸ Cochabamba",
    ])->assertStatus(409);

    expect($r->json('duplicado_de'))->toBe($m->id);

    // Y no se encoló nada: el cliente no recibe el segundo mensaje.
    expect(Message::where('conversation_id', $this->conv->id)->where('type', 'text')->count())->toBe(0);
});

it('tolera diferencias de espaciado y mayúsculas', function () {
    // El modelo reescribe la pregunta con otro espaciado cada vez; comparar en
    // crudo no encontraría nada.
    menuInteractivo($this->conv, '¿De qué ciudad nos escribe?');

    test()->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/messages", [
        'text' => '¿DE QUÉ  CIUDAD   nos escribe?   ▸ Santa Cruz',
    ])->assertStatus(409);
});

it('deja pasar un texto distinto después de un menú', function () {
    // Mandar botones y después una aclaración es legítimo y no se toca.
    menuInteractivo($this->conv, '¿De qué ciudad nos escribe?');

    test()->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/messages", [
        'text' => 'Si no ves los botones, escribime el nombre de tu ciudad.',
    ])->assertOk();
});

it('deja pasar cuando el último saliente no es un menú', function () {
    Message::create([
        'conversation_id' => $this->conv->id,
        'direction'       => 'outbound',
        'type'            => 'text',
        'body'            => '¿De qué ciudad nos escribe?',
        'status'          => 'sent',
        'sender'          => 'ia',
    ]);

    test()->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/messages", [
        'text' => '¿De qué ciudad nos escribe? ▸ Santa Cruz',
    ])->assertOk();
});

it('no bloquea la misma pregunta en otro momento de la charla', function () {
    // Dos preguntas iguales separadas por la respuesta del cliente son
    // legítimas: la defensa mira solo la ventana reciente.
    $viejo = menuInteractivo($this->conv, '¿De qué ciudad nos escribe?');

    $viejo->forceFill(['created_at' => now()->subMinutes(30)])->save();

    test()->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/messages", [
        'text' => '¿De qué ciudad nos escribe? ▸ Santa Cruz',
    ])->assertOk();
});

it('se puede desactivar por config', function () {
    config(['whatsapp.antiduplicado_segundos' => 0]);

    menuInteractivo($this->conv, '¿De qué ciudad nos escribe?');

    test()->postJson("/api/v1/whatsapp/conversations/{$this->conv->id}/messages", [
        'text' => '¿De qué ciudad nos escribe? ▸ Santa Cruz',
    ])->assertOk();
});

it('ninguna función global de test pisa un helper de la aplicación', function () {
    /*
     * Regresión de un bug que no se veía en este archivo sino en otros: una
     * función `menu()` acá pisaba el helper global de Krayin y TODAS las vistas
     * del admin devolvían 500 — pero solo cuando la suite corría completa, y el
     * error aparecía en tests de productos que no tienen nada que ver.
     *
     * Pest carga los archivos de test antes de correrlos, así que cualquier
     * función global que declaren queda definida para todo el proceso.
     */
    $helpers = [];

    foreach (glob(base_path('packages/Webkul/*/src/Http/helpers.php')) as $archivo) {
        preg_match_all('/^\s*function\s+(\w+)\s*\(/m', file_get_contents($archivo), $m);

        $helpers = array_merge($helpers, $m[1]);
    }

    expect($helpers)->not->toBeEmpty();

    $enTests = [];

    foreach (glob(base_path('tests/Feature/**/*.php')) as $archivo) {
        preg_match_all('/^function\s+(\w+)\s*\(/m', file_get_contents($archivo), $m);

        $enTests = array_merge($enTests, $m[1]);
    }

    expect(array_intersect($enTests, $helpers))->toBe([]);
});
