<?php

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\FiltroEjecutivo;
use Webkul\User\Models\User;

/**
 * Sincronización del filtro «Ejecutivo comercial» (20/10/2026, spec
 * pedidos-filtro-ejecutivo, «Sincronización»): la elección se recuerda en la
 * cookie `global_ejecutivo`, vale en Tablero, Pedidos, Cotizaciones y
 * Contactos, y solo para Administrador y Supervisores. Todo el 15/01/2020.
 */
beforeEach(function () {
    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $this->marca = 'Recordado '.uniqid();
    $fecha = '2020-01-15 10:00:00';
    $etapa = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->where('code', 'no-atendidos')->value('id');

    foreach (['bulmaro', 'eduardo'] as $quien) {
        $persona = DB::table('persons')->insertGetId([
            'name' => "{$this->marca} {$quien}", 'user_id' => $this->{$quien}->id, 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);

        $this->{"pedidoDe_$quien"} = DB::table('leads')->insertGetId([
            'title'            => "{$this->marca} {$quien}", 'user_id' => $this->{$quien}->id, 'status' => 1, 'person_id' => $persona,
            'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => $etapa, 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);

        $this->{"personaDe_$quien"} = $persona;

        $this->{"cotizacionDe_$quien"} = DB::table('quotes')->insertGetId([
            'subject'    => "{$this->marca} {$quien}", 'user_id' => $this->{$quien}->id, 'person_id' => $persona,
            'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
    }

    $this->fechas = ['pipeline_id' => 1, 'date_filter' => 'custom', 'date_from' => '2020-01-15', 'date_to' => '2020-01-15'];
});

function kanbanRecordado(array $params): array
{
    return collect(test()->getJson(route('admin.leads.get', $params))->assertOk()->json())
        ->flatMap(fn ($columna) => array_column($columna['leads']['data'] ?? [], 'id'))
        ->all();
}

function grillaRecordada(string $ruta, string $marca, array $extra = []): array
{
    $ids = array_column(test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
        ->get(route($ruta, ['filters' => ['all' => [$marca]], 'pagination' => ['page' => 1, 'per_page' => 50]] + $extra))
        ->assertOk()
        ->json('records'), 'id');

    test()->flushHeaders(); // las páginas que se piden después no son AJAX

    return $ids;
}

it('elegir en Pedidos lo recuerda y «todos» lo olvida', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    test()->get(route('admin.leads.index', [...$this->fechas, 'ejecutivo' => $this->bulmaro->id]))
        ->assertPlainCookie(FiltroEjecutivo::COOKIE, (string) $this->bulmaro->id);

    test()->get(route('admin.leads.index', [...$this->fechas, 'ejecutivo' => '']))
        ->assertCookieExpired(FiltroEjecutivo::COOKIE);
});

it('con la cookie, Pedidos, Tablero, Cotizaciones y Contactos muestran solo ese ejecutivo', function () {
    // withCredentials: sin eso los pedidos JSON del test no mandan cookies.
    test()->actingAs(getDefaultAdmin(), 'user')->withCredentials()
        ->withUnencryptedCookie(FiltroEjecutivo::COOKIE, (string) $this->bulmaro->id);

    expect(kanbanRecordado($this->fechas))->toContain($this->pedidoDe_bulmaro)->not->toContain($this->pedidoDe_eduardo)
        ->and(grillaRecordada('admin.quotes.index', $this->marca))->toBe([$this->cotizacionDe_bulmaro])
        ->and(grillaRecordada('admin.contacts.persons.index', $this->marca))->toBe([$this->personaDe_bulmaro]);

    $tablero = test()->withHeaders(['Accept' => 'application/json'])
        ->get(route('admin.dashboard.stats', ['type' => 'leads-attention-by-users', 'start' => '2020-01-01', 'end' => '2020-01-31']))
        ->assertOk()->json('statistics');

    expect($tablero['labels'])->toBe(['Bulmaro Vaca']);

    // La URL manda sobre la cookie: vacío = todos.
    expect(kanbanRecordado([...$this->fechas, 'ejecutivo' => '']))->toContain($this->pedidoDe_bulmaro, $this->pedidoDe_eduardo);
});

it('Cotizaciones y Contactos filtran por el parámetro y tienen el selector', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    expect(grillaRecordada('admin.quotes.index', $this->marca, ['ejecutivo' => $this->eduardo->id]))->toBe([$this->cotizacionDe_eduardo])
        ->and(grillaRecordada('admin.contacts.persons.index', $this->marca, ['ejecutivo' => $this->eduardo->id]))->toBe([$this->personaDe_eduardo])
        ->and(test()->get(route('admin.quotes.index'))->assertOk()->getContent())->toContain('aria-label="Ejecutivo comercial"')
        ->and(test()->get(route('admin.contacts.persons.index', ['pipeline_id' => '']))->assertOk()->getContent())->toContain('aria-label="Ejecutivo comercial"');
});

it('a un ejecutivo comercial la cookie de otro no le cambia nada ni ve el selector', function () {
    test()->actingAs($this->bulmaro, 'user')->withCredentials()
        ->withUnencryptedCookie(FiltroEjecutivo::COOKIE, (string) $this->eduardo->id);

    expect(kanbanRecordado($this->fechas))->toContain($this->pedidoDe_bulmaro)->not->toContain($this->pedidoDe_eduardo)
        ->and(test()->get(route('admin.quotes.index'))->assertOk()->getContent())->not->toContain('aria-label="Ejecutivo comercial"');
});
