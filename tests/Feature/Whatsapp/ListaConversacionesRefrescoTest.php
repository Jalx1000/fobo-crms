<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Lista de conversaciones del módulo WhatsApp: el refresco cada 10 s no debe
 * achicarla.
 *
 * Caso real (2026-10-07): el refresco pedía solo la página 1 y reemplazaba la
 * lista; con "Cargar más" ya usado, la lista volvía a 30, la página se acortaba
 * y el asesor saltaba al tope. Ahora el refresco pide de una vez todo lo que ya
 * estaba cargado (per_page = páginas cargadas × 30), con un tope.
 *
 * Contra la base del contenedor con transacciones.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->actingAs(User::findOrFail(1), 'user');

    // Suficientes para que haya más de una página, sean cuantas sean las reales.
    foreach (range(1, 35) as $i) {
        Conversation::create([
            'gateway'    => 'cloud_api', 'wa_phone' => '+5916'.random_int(1000000, 9999999), 'wa_name' => "LC $i",
            'ai_enabled' => false, 'status' => 'open', 'last_message_at' => now()->subSeconds($i),
        ]);
    }
});

function listaConversaciones(array $params = [])
{
    return test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.whatsapp.conversations', $params))
        ->assertOk();
}

it('sin per_page sigue paginando de a 30', function () {
    expect(listaConversaciones()->json('per_page'))->toBe(30)
        ->and(count(listaConversaciones()->json('data')))->toBe(30);
});

it('el refresco puede pedir de una vez todo lo cargado (2 páginas = 60)', function () {
    $total = DB::table('whatsapp_conversations')->count();

    $respuesta = listaConversaciones(['page' => 1, 'per_page' => 60]);

    expect($respuesta->json('per_page'))->toBe(60)
        ->and(count($respuesta->json('data')))->toBe(min(60, $total));
});

it('per_page tiene tope y piso', function () {
    expect(listaConversaciones(['per_page' => 100000])->json('per_page'))->toBe(300)
        ->and(listaConversaciones(['per_page' => 0])->json('per_page'))->toBe(30)
        ->and(listaConversaciones(['per_page' => 'x'])->json('per_page'))->toBe(30);
});
