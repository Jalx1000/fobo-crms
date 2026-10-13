<?php

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Quote\Repositories\QuoteRepository;
use Webkul\User\Models\User;
use Webkul\Whatsapp\Jobs\SeguimientoCotizacion;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\Message;

/**
 * Etapas automáticas al enviar una cotización (13/10): el pedido pasa a
 * COTIZACION ENVIADA; si el cliente no contesta, recordatorio al asesor a los
 * 3 días y SIN INTERÉS a los 7. Nada sale: correo y cola falseados.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
    Queue::fake();

    $this->asesor = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->persona = DB::table('persons')->insertGetId(['name' => 'Seguimiento '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);

    $this->etapa = fn (string $code) => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->where('code', $code)->value('id');

    $this->lead = DB::table('leads')->insertGetId([
        'title'                  => 'Seguimiento '.uniqid(), 'person_id' => $this->persona, 'user_id' => $this->asesor->id, 'status' => 1,
        'lead_pipeline_id'       => 1, 'lead_pipeline_stage_id' => ($this->etapa)('solicitud-recibida'),
        'created_at'             => now(), 'updated_at' => now(),
    ]);

    $this->quote = DB::table('quotes')->insertGetId([
        'subject'    => 'Seguimiento', 'user_id' => $this->asesor->id, 'person_id' => $this->persona, 'sub_total' => 100, 'grand_total' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('lead_quotes')->insert(['lead_id' => $this->lead, 'quote_id' => $this->quote]);

    $this->conv = Conversation::create([
        'gateway'    => 'kommo', 'wa_phone' => '+5917'.random_int(1000000, 9999999), 'wa_name' => 'Seg',
        'ai_enabled' => true, 'status' => 'open', 'person_id' => $this->persona, 'lead_id' => $this->lead,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function etapaDe(int $lead): string
{
    return DB::table('lead_pipeline_stages')->where('id', DB::table('leads')->where('id', $lead)->value('lead_pipeline_stage_id'))->value('code');
}

function seguimiento($test, string $paso, string $enviada = '-8 days'): void
{
    (new SeguimientoCotizacion($test->lead, $test->quote, now()->modify($enviada)->toIso8601String(), $paso))
        ->handle(app(\Webkul\Lead\Services\EtapaPedido::class));
}

it('al enviar la cotización el pedido pasa a COTIZACION ENVIADA y se agenda el seguimiento', function () {
    Event::dispatch('quote.whatsapp.after', app(QuoteRepository::class)->find($this->quote));

    expect(etapaDe($this->lead))->toBe('cotizacion-enviada');

    Queue::assertPushed(SeguimientoCotizacion::class, 2);
});

it('un pedido que ya está más adelante no retrocede', function () {
    DB::table('leads')->where('id', $this->lead)->update(['lead_pipeline_stage_id' => ($this->etapa)('entregados')]);

    Event::dispatch('quote.whatsapp.after', app(QuoteRepository::class)->find($this->quote));

    expect(etapaDe($this->lead))->toBe('entregados');
});

it('sin respuesta del cliente, a los 7 días pasa a SIN INTERÉS', function () {
    DB::table('leads')->where('id', $this->lead)->update(['lead_pipeline_stage_id' => ($this->etapa)('cotizacion-enviada')]);

    seguimiento($this, SeguimientoCotizacion::SIN_INTERES);

    expect(etapaDe($this->lead))->toBe('sin-inters');
});

it('si el cliente contestó después del envío, no pasa a SIN INTERÉS', function () {
    DB::table('leads')->where('id', $this->lead)->update(['lead_pipeline_stage_id' => ($this->etapa)('cotizacion-enviada')]);

    $m = Message::create(['conversation_id' => $this->conv->id, 'direction' => 'inbound', 'type' => 'text', 'sender' => 'contact', 'status' => 'received', 'body' => 'Lo estoy pensando']);
    $m->forceFill(['created_at' => now()->subDays(2)])->save();

    seguimiento($this, SeguimientoCotizacion::SIN_INTERES);

    expect(etapaDe($this->lead))->toBe('cotizacion-enviada');
});

it('el recordatorio no mueve el pedido', function () {
    DB::table('leads')->where('id', $this->lead)->update(['lead_pipeline_stage_id' => ($this->etapa)('cotizacion-enviada')]);

    seguimiento($this, SeguimientoCotizacion::RECORDATORIO, '-3 days');

    expect(etapaDe($this->lead))->toBe('cotizacion-enviada');
});

it('si el asesor ya lo movió, el seguimiento no hace nada', function () {
    seguimiento($this, SeguimientoCotizacion::SIN_INTERES);

    expect(etapaDe($this->lead))->toBe('solicitud-recibida');
});
