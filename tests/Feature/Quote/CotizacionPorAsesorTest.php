<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Webkul\Quote\Repositories\QuoteRepository;
use Webkul\User\Models\User;

/**
 * Cotizaciones por asesor (spec docs/specs/tablero-por-asesor.md, D13c): un
 * ejecutivo abre, imprime, envía y edita solo sus cotizaciones (las suyas o
 * las de sus pedidos). Antes cualquier ruta aceptaba el id de otra.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    // Bulmaro como ejecutivo común: acá no es encargado nacional (ver EncargadoNacionalTest).
    config(['reporting.encargados_nacionales' => []]);

    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $this->mariano = User::where('name', 'Mariano Pinell')->firstOrFail();

    $nueva = function (User $asesor) {
        $persona = DB::table('persons')->insertGetId(['name' => 'Cot '.uniqid(), 'user_id' => $asesor->id, 'created_at' => now(), 'updated_at' => now()]);
        $lead = DB::table('leads')->insertGetId([
            'person_id'              => $persona, 'title' => 'Cot '.uniqid(), 'user_id' => $asesor->id, 'status' => 1, 'lead_pipeline_id' => 1,
            'lead_pipeline_stage_id' => DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->orderBy('sort_order')->value('id'),
            'created_at'             => now(), 'updated_at' => now(),
        ]);
        $quote = DB::table('quotes')->insertGetId([
            'subject'    => 'Cot', 'user_id' => $asesor->id, 'person_id' => $persona, 'sub_total' => 0, 'grand_total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lead_quotes')->insert(['lead_id' => $lead, 'quote_id' => $quote]);

        return ['quote' => $quote, 'lead' => $lead];
    };

    $this->deBulmaro = $nueva($this->bulmaro);
    $this->deEduardo = $nueva($this->eduardo);
});

it('cada ruta de una cotización ajena le responde 404 a un ejecutivo', function () {
    $id = $this->deEduardo['quote'];
    $rutas = [
        ['GET', route('admin.quotes.edit', $id)],
        ['PUT', route('admin.quotes.update', $id)],
        ['GET', route('admin.quotes.print', $id)],
        ['POST', route('admin.quotes.send', $id)],
        ['POST', route('admin.quotes.whatsapp', $id)],
        ['POST', route('admin.quotes.messenger', $id)],
        ['DELETE', route('admin.quotes.delete', $id)],
        ['GET', route('admin.quotes.create', ['lead_id' => $this->deEduardo['lead']])],
    ];

    $estados = collect($rutas)->mapWithKeys(fn ($r) => [$r[0].' '.$r[1] => test()->actingAs($this->bulmaro, 'user')
        ->withHeaders(['Accept' => 'application/json'])->json($r[0], $r[1], ['subject' => 'x'])->baseResponse->getStatusCode()]);

    // 401 = lo corta antes el ACL de Krayin (sin permiso): también es «no».
    expect($estados->reject(fn ($s) => in_array($s, [404, 401], true))->all())->toBe([])
        ->and(DB::table('quotes')->where('id', $id)->exists())->toBeTrue();
});

it('un ejecutivo abre su propia cotización', function () {
    test()->actingAs($this->bulmaro, 'user')->get(route('admin.quotes.edit', $this->deBulmaro['quote']))->assertOk();
});

it('el supervisor abre la cotización de cualquiera', function () {
    test()->actingAs($this->mariano, 'user')->get(route('admin.quotes.edit', $this->deEduardo['quote']))->assertOk();
});

it('un ejecutivo no cambia el «Asesor de venta» de su cotización', function () {
    $this->actingAs($this->bulmaro, 'user');
    app(QuoteRepository::class)->update(['user_id' => $this->eduardo->id, 'entity_type' => 'quotes'], $this->deBulmaro['quote']);

    expect((int) DB::table('quotes')->where('id', $this->deBulmaro['quote'])->value('user_id'))->toBe($this->bulmaro->id);
});
