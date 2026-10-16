<?php

use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/**
 * Pedidos: selector «Ejecutivo comercial» al lado del de ciudad (16/10/2026).
 * Filtra el kanban y la lista; nunca amplía lo que el usuario ya puede ver.
 * Todo el 15/01/2020, para no mezclarse con los datos reales.
 */
beforeEach(function () {
    $this->bulmaro = User::where('name', 'Bulmaro Vaca')->firstOrFail();
    $this->eduardo = User::where('name', 'Eduardo Lujan')->firstOrFail();
    $etapa = DB::table('lead_pipeline_stages')->where('lead_pipeline_id', 1)->where('code', 'no-atendidos')->value('id');

    // Todo pedido real tiene contacto (la lista enlaza a él).
    $persona = DB::table('persons')->insertGetId([
        'name' => 'Filtro ejecutivo '.uniqid(), 'created_at' => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
    ]);

    $this->pedido = fn (User $u) => DB::table('leads')->insertGetId([
        'title'            => 'Filtro ejecutivo '.uniqid(), 'user_id' => $u->id, 'status' => 1, 'person_id' => $persona,
        'lead_pipeline_id' => 1, 'lead_pipeline_stage_id' => $etapa,
        'created_at'       => '2020-01-15 10:00:00', 'updated_at' => '2020-01-15 10:00:00',
    ]);

    $this->deBulmaro = ($this->pedido)($this->bulmaro);
    $this->deEduardo = ($this->pedido)($this->eduardo);
    $this->fechas = ['date_filter' => 'custom', 'date_from' => '2020-01-15', 'date_to' => '2020-01-15'];
});

function idsDelKanban(array $params): array
{
    return collect(test()->getJson(route('admin.leads.get', array_merge(['pipeline_id' => 1], $params)))->assertOk()->json())
        ->flatMap(fn ($columna) => array_column($columna['leads']['data'] ?? [], 'id'))
        ->all();
}

function idsDeLaLista(array $params): array
{
    return array_column(
        test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('admin.leads.index', array_merge(['pipeline_id' => 1, 'view_type' => 'table'], $params)))->assertOk()->json('records'),
        'id'
    );
}

it('el administrador filtra el kanban y la lista por ejecutivo', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    expect(idsDelKanban($this->fechas))->toContain($this->deBulmaro, $this->deEduardo)
        ->and(idsDelKanban([...$this->fechas, 'ejecutivo' => $this->bulmaro->id]))
        ->toContain($this->deBulmaro)->not->toContain($this->deEduardo)
        ->and(idsDeLaLista([...$this->fechas, 'ejecutivo' => $this->eduardo->id]))
        ->toContain($this->deEduardo)->not->toContain($this->deBulmaro);
});

it('el selector está al lado de la ciudad y lista solo ejecutivos comerciales', function () {
    test()->actingAs(getDefaultAdmin(), 'user');

    $html = test()->get(route('admin.leads.index', ['pipeline_id' => 1, 'ejecutivo' => $this->bulmaro->id]))->assertOk()->getContent();

    expect($html)->toContain('aria-label="Ejecutivo comercial"')
        ->toContain('Bulmaro Vaca')->toContain('Eduardo Lujan')
        ->not->toContain('>Mariano Pinell<')
        // Cambiar de ciudad conserva el ejecutivo elegido.
        ->toMatch('/pipeline_id=all[^"]*ejecutivo='.$this->bulmaro->id.'|ejecutivo='.$this->bulmaro->id.'[^"]*pipeline_id=all/');
});

it('un asesor no ve pedidos ajenos aunque elija a otro ejecutivo', function () {
    test()->actingAs($this->bulmaro, 'user');

    expect(idsDelKanban([...$this->fechas, 'ejecutivo' => $this->eduardo->id]))->toBe([])
        ->and(idsDeLaLista([...$this->fechas, 'ejecutivo' => $this->eduardo->id]))->toBe([])
        ->and(idsDelKanban([...$this->fechas, 'ejecutivo' => $this->bulmaro->id]))->toContain($this->deBulmaro);
});
