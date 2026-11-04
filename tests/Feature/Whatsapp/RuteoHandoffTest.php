<?php

use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;
use Webkul\Whatsapp\Services\AgentPayload;

/**
 * El CRM deja de rutear al agente apenas se pide la derivación.
 *
 * Reportado por el equipo del agente (14/09): con el handoff en `requested` la
 * IA seguía recibiendo mensajes y arrancaba un segundo flujo sobre una
 * conversación ya derivada.
 *
 * La causa era una inconsistencia nuestra: el gate de ruteo miraba
 * `aiEffective()` -«¿está encendida la IA?»- mientras que el endpoint de envío
 * rechazaba con 409 por `handoffOpen()`. El CRM invitaba a responder y después
 * rechazaba la respuesta.
 */
beforeEach(function () {
    // Sin asesor por defecto: el ruteo que se prueba acá es el de la matriz.
    config(['cotizacion.asesor_por_defecto' => null]);

    $this->conv = Conversation::create([
        'gateway'    => 'cloud_api',
        'wa_phone'   => '59177006'.rand(100, 999),
        'ai_enabled' => true,
    ]);
});

afterEach(function () {
    if (isset($this->conv)) {
        Message::where('conversation_id', $this->conv->id)->delete();
        Conversation::whereKey($this->conv->id)->delete();
    }
});

it('rutea al agente cuando no hay derivación', function () {
    expect($this->conv->agenteDebeResponder())->toBeTrue();
});

it('deja de rutear con el handoff en requested, no solo en assigned', function () {
    foreach ([Conversation::HANDOFF_REQUESTED, Conversation::HANDOFF_ASSIGNED] as $estado) {
        $this->conv->forceFill(['handoff_state' => $estado])->save();

        expect($this->conv->fresh()->agenteDebeResponder())
            ->toBeFalse("con handoff «{$estado}» el turno es del asesor");
    }
});

it('la IA sigue encendida: lo que cambia es de quién es el turno', function () {
    // El asesor puede devolver la conversación a la IA; apagarla sería otra cosa.
    $this->conv->forceFill(['handoff_state' => Conversation::HANDOFF_REQUESTED])->save();

    $fresca = $this->conv->fresh();

    expect($fresca->aiEffective())->toBeTrue();
    expect($fresca->handoffOpen())->toBeTrue();
    expect($fresca->agenteDebeResponder())->toBeFalse();
});

it('el payload le dice al agente que no conteste', function () {
    $this->conv->forceFill(['handoff_state' => Conversation::HANDOFF_REQUESTED])->save();

    $mensaje = Message::create([
        'conversation_id' => $this->conv->id,
        'direction'       => 'inbound',
        'type'            => 'text',
        'body'            => 'sigo escribiendo',
        'status'          => 'delivered',
        'sender'          => 'contact',
    ]);

    $payload = app(AgentPayload::class)->event($mensaje->fresh());

    expect($payload['ai_enabled'])->toBeFalse();
    expect($payload['handoff']['open'])->toBeTrue();
    expect($payload['handoff']['state'])->toBe(Conversation::HANDOFF_REQUESTED);
});

it('el gate de ruteo y el de envío miran lo mismo', function () {
    /*
     * Regresión de la inconsistencia: si alguien vuelve a poner `aiEffective()`
     * en los jobs de ruteo, el agente vuelve a recibir mensajes que después no
     * puede contestar.
     */
    foreach ([
        'packages/Webkul/Whatsapp/src/Jobs/IngestInboundPayload.php',
        'packages/Webkul/Whatsapp/src/Jobs/NotifyAgent.php',
    ] as $archivo) {
        $codigo = file_get_contents(base_path($archivo));

        expect($codigo)->toContain('agenteDebeResponder()')
            ->and($codigo)->not->toContain('aiEffective()');
    }
});

it('el historial dice quién escribió cada mensaje, no solo si es entrante', function () {
    /*
     * Reportado el 16/09: dos clientes escribieron tres y cuatro veces sin que
     * nadie les contestara. El CRM entregaba los eventos -el gate estaba
     * abierto y el agente contestaba en otras conversaciones el mismo minuto-,
     * pero el historial mandaba TODO lo saliente como `assistant`, así que las
     * palabras del asesor le llegaban al agente como propias. Un «Ok gracias
     * por escribirnos» escrito por un humano lo dejaba convencido de que ya se
     * había despedido.
     */
    Message::create([
        'conversation_id' => $this->conv->id,
        'direction'       => 'inbound',
        'type'            => 'text',
        'sender'          => 'contact',
        'body'            => 'Hola',
        'status'          => 'delivered',
    ]);

    Message::create([
        'conversation_id' => $this->conv->id,
        'direction'       => 'outbound',
        'type'            => 'text',
        'sender'          => 'ia',
        'body'            => '¿En qué le ayudo?',
        'status'          => 'delivered',
    ]);

    Message::create([
        'conversation_id' => $this->conv->id,
        'direction'       => 'outbound',
        'type'            => 'text',
        'sender'          => 'human',
        'body'            => 'Ok gracias por escribirnos',
        'status'          => 'delivered',
    ]);

    $history = app(AgentPayload::class)->history($this->conv->refresh());

    expect($history)->toHaveCount(3);

    // El `role` no cambia: los modelos esperan user/assistant.
    expect(array_column($history, 'role'))->toBe(['user', 'assistant', 'assistant']);

    // El quién viaja al lado, y es lo que deja distinguir al asesor de la IA.
    expect(array_column($history, 'sender'))->toBe(['contact', 'ia', 'human']);
});
