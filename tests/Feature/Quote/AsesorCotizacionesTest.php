<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\User\Models\User;

/**
 * Las cotizaciones siguen al asesor de su pedido (13/10): el reparto cambiaba
 * el dueño del pedido y la cotización quedaba con el anterior.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->fabio = User::where('name', 'Fabio Sandoval')->firstOrFail();
    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();

    $persona = DB::table('persons')->insertGetId(['name' => 'Asesor cot '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);

    $this->lead = DB::table('leads')->insertGetId([
        'person_id'              => $persona,
        'title'                  => 'Asesor cot '.uniqid(), 'user_id' => $this->bulmaro->id, 'status' => 1,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
        'created_at'             => now(), 'updated_at' => now(),
    ]);

    $this->quote = DB::table('quotes')->insertGetId([
        'subject'    => 'Asesor cot', 'user_id' => $this->fabio->id, 'person_id' => $persona, 'sub_total' => 0, 'grand_total' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('lead_quotes')->insert(['lead_id' => $this->lead, 'quote_id' => $this->quote]);
});

it('la cotización pasa al asesor de su pedido', function () {
    \Webkul\Product\Services\Responsables::cotizacionesConElPedido($this->lead);

    expect((int) DB::table('quotes')->where('id', $this->quote)->value('user_id'))->toBe($this->bulmaro->id);
});

it('al actualizar el pedido, sus cotizaciones lo siguen', function () {
    Event::dispatch('lead.update.after', app(LeadRepository::class)->find($this->lead));

    expect((int) DB::table('quotes')->where('id', $this->quote)->value('user_id'))->toBe($this->bulmaro->id);
});
