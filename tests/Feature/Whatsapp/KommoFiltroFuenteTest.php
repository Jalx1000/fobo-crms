<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Webkul\Whatsapp\Gateways\Kommo\KommoGateway;

/**
 * Solo entran los mensajes de los números (fuentes) de Imprimir.
 *
 * La cuenta de Kommo tiene también el número de Odontoking y el webhook avisa
 * de los dos sin decir por cuál llegó: el 30/10/2026 entraron 249 mensajes de
 * pacientes de Odontoking. La fuente sale del talk (GET /api/v4/talks/{id}).
 *
 * Sin base de datos: Kommo está falseado.
 */
const FUENTE_IMPRIMIR = '23140613';
const FUENTE_ODONTOKING = '23135697';

beforeEach(function () {
    Cache::flush();

    Http::fake([
        'acme.kommo.com/api/v4/contacts/*' => Http::response([
            'custom_fields_values' => [['field_code' => 'PHONE', 'values' => [['value' => '+59170000000']]]],
        ]),
        'acme.kommo.com/api/v4/talks/100' => Http::response(['talk_id' => 100, 'source_id' => (int) FUENTE_IMPRIMIR]),
        'acme.kommo.com/api/v4/talks/200' => Http::response(['talk_id' => 200, 'source_id' => (int) FUENTE_ODONTOKING]),
        'acme.kommo.com/api/v4/talks/500' => Http::response(['error' => 'boom'], 500),
    ]);
});

function filtroKommo(?string $permitidas = FUENTE_IMPRIMIR): KommoGateway
{
    return new KommoGateway(['subdomain' => 'acme', 'token' => 'kommo-token', 'allowed_source_ids' => $permitidas]);
}

function eventoDeTalk(?int $talkId, string $id = 'msg-1'): array
{
    return ['message' => ['add' => [array_filter([
        'id'         => $id,
        'type'       => 'incoming',
        'contact_id' => '42',
        'element_id' => '555',
        'talk_id'    => $talkId,
        'text'       => 'Hola',
        'author'     => ['name' => 'Cliente'],
    ], fn ($v) => $v !== null)]]];
}

it('deja entrar el mensaje que llegó por el número de Imprimir', function () {
    expect(filtroKommo()->parseWebhook(eventoDeTalk(100))->messages)->toHaveCount(1);
});

it('descarta el mensaje que llegó por el número de Odontoking', function () {
    expect(filtroKommo()->parseWebhook(eventoDeTalk(200))->messages)->toBeEmpty();
});

it('sin fuentes configuradas no filtra ni consulta a Kommo', function () {
    expect(filtroKommo('')->parseWebhook(eventoDeTalk(200))->messages)->toHaveCount(1);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/talks/'));
});

it('acepta varias fuentes separadas por coma', function () {
    expect(filtroKommo(FUENTE_ODONTOKING.', '.FUENTE_IMPRIMIR)->parseWebhook(eventoDeTalk(200))->messages)->toHaveCount(1);
});

it('consulta la fuente una sola vez por talk', function () {
    $gateway = filtroKommo();

    $gateway->parseWebhook(eventoDeTalk(200, 'a'));
    $gateway->parseWebhook(eventoDeTalk(200, 'b'));

    Http::assertSentCount(1);
});

it('si Kommo no responde, el mensaje entra y se vuelve a consultar la próxima vez', function () {
    $gateway = filtroKommo();

    expect($gateway->parseWebhook(eventoDeTalk(500, 'a'))->messages)->toHaveCount(1);
    $gateway->parseWebhook(eventoDeTalk(500, 'b'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/talks/500'));
    expect(collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), '/talks/500')))->toHaveCount(2);
});

it('un mensaje sin talk_id entra', function () {
    expect(filtroKommo()->parseWebhook(eventoDeTalk(null))->messages)->toHaveCount(1);
});
