<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Jobs\NotifyAgent;
use Webkul\Whatsapp\Mail\AgenteConErrores;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;

/**
 * CRM-3: avisar por correo cuando el agente falla.
 *
 * Auditoría del 12/10: del 07/10 21:08 al 08/10 08:48 el agente contestó 23
 * veces «Disculpe, ocurrió un error…» y nadie se enteró en 12 horas. Ahora, con
 * 3 fallas en 15 minutos sale UN correo a los dos destinatarios fijos, y no se
 * repite durante una hora.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    actingAsSanctumAuthenticatedAdmin();
    Mail::fake();
    Queue::fake();
    Cache::flush();

    config([
        'whatsapp.alertas.emails'           => ['jalejandromogro1@gmail.com', 'dcuellar@sofopolis.com'],
        'whatsapp.alertas.umbral'           => 3,
        'whatsapp.alertas.ventana_minutos'  => 15,
        'whatsapp.alertas.silencio_minutos' => 60,
    ]);

    $this->conv = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Alerta',
        'ai_enabled' => true, 'status' => 'open', 'last_inbound_at' => now(),
    ]);
});

function alertaResponde($conv, string $texto)
{
    return test()->postJson("/api/v1/whatsapp/conversations/{$conv->id}/messages", ['text' => $texto]);
}

const ALERTA_ERROR = 'Disculpe, ocurrió un error. Por favor intente de nuevo en un momento 🙏.';

it('tres respuestas de error en 15 minutos mandan un correo a los dos destinatarios', function () {
    foreach (range(1, 3) as $i) {
        alertaResponde($this->conv, ALERTA_ERROR)->assertOk();
    }

    Mail::assertSent(AgenteConErrores::class, 1);
    Mail::assertSent(AgenteConErrores::class, fn ($m) => $m->hasTo('jalejandromogro1@gmail.com') && $m->hasTo('dcuellar@sofopolis.com'));
});

it('dos errores todavía no avisan', function () {
    alertaResponde($this->conv, ALERTA_ERROR);
    alertaResponde($this->conv, ALERTA_ERROR);

    Mail::assertNothingSent();
});

it('no repite el correo durante la hora de silencio', function () {
    foreach (range(1, 7) as $i) {
        alertaResponde($this->conv, ALERTA_ERROR);
    }

    Mail::assertSent(AgenteConErrores::class, 1);
});

it('las respuestas normales no cuentan', function () {
    foreach (range(1, 5) as $i) {
        alertaResponde($this->conv, "Hola, ¿de qué ciudad nos escribe? ($i)");
    }

    Mail::assertNothingSent();
});

it('el agente que no recibe el mensaje tras los reintentos también cuenta', function () {
    foreach (range(1, 3) as $i) {
        $m = Message::create([
            'conversation_id' => $this->conv->id, 'direction' => 'inbound', 'type' => 'text',
            'sender'          => 'contact', 'status' => 'delivered', 'body' => "Hola $i",
        ]);

        (new NotifyAgent($m->id))->failed(new RuntimeException('Connection refused'));
    }

    Mail::assertSent(AgenteConErrores::class, 1);
});

it('sin destinatarios configurados no manda nada', function () {
    config(['whatsapp.alertas.emails' => []]);

    foreach (range(1, 3) as $i) {
        alertaResponde($this->conv, ALERTA_ERROR);
    }

    Mail::assertNothingSent();
});
