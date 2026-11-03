<?php

use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

/*
 * Correcciones de la auditoría del tablero (19/10/2026).
 *
 * - "Prospecto" se buscaba por nombre y la etapa ya no existe: los pedidos
 *   abiertos sólo contaban los confirmados.
 * - El bloque de administrador comparaba el rol con 'admin' y el rol se llama
 *   "Administrador": no lo veía nadie.
 * - El usuario del rol Sistema (el agente) salía como vendedor.
 *
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una transacción.
 */
beforeEach(function () {
    DB::beginTransaction();
    actingAsSanctumAuthenticatedAdmin();
    test()->actingAs(getDefaultAdmin(), 'user');
});

afterEach(fn () => DB::rollBack());

function statsDelTablero(string $type, array $extra = []): array
{
    return test()->getJson(route('admin.dashboard.stats', array_merge([
        'type'        => $type,
        'start'       => '2026-10-09',
        'end'         => '2026-10-15',
        'pipeline_id' => '',
    ], $extra)))->assertSuccessful()->json('statistics');
}

it('los pedidos abiertos incluyen No atendido y Cliente contactado, no sólo confirmados', function () {
    $abiertos = DB::table('leads as l')
        ->join('lead_pipeline_stages as s', 's.id', '=', 'l.lead_pipeline_stage_id')
        ->whereIn(DB::raw("TRIM(LEADING '-' FROM ".DB::getTablePrefix().'s.code)'), ['no-atendido', 'cliente-contactado', 'cliente-confirmado'])
        ->whereBetween('l.created_at', ['2026-10-09 00:00:00', '2026-10-15 23:59:59'])
        ->count();

    expect(statsDelTablero('over-all')['total_leads']['current'])->toBe($abiertos);
});

it('el administrador ve el bloque de ventas por vendedor y ciudad', function () {
    $html = test()->get(route('admin.dashboard.index'))->assertSuccessful()->getContent();

    expect($html)->toContain('<v-dashboard-ventas>');
});

it('un encargado no ve el bloque de administrador', function () {
    $encargado = User::query()
        ->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.name', 'Encargado')
        ->where('users.status', 1)
        ->select('users.*')
        ->first();

    test()->actingAs($encargado, 'user');

    $html = test()->get(route('admin.dashboard.index'))->assertSuccessful()->getContent();

    expect($html)->not->toContain('<v-dashboard-ventas>')
        ->and($html)->toContain('<v-dashboard-tiempo-por-vendedor>');
});

it('el usuario del rol Sistema no aparece como vendedor', function (string $type) {
    $agentes = DB::table('users')
        ->join('roles', 'roles.id', '=', 'users.role_id')
        ->where('roles.name', 'Sistema')
        ->pluck('users.name')
        ->all();

    expect(statsDelTablero($type)['labels'])->not->toContain(...$agentes);
})->with(['ventas', 'leads-by-users']);

it('la tarjeta de WhatsApp ya no está en el tablero', function () {
    $html = test()->get(route('admin.dashboard.index'))->getContent();

    expect($html)->not->toContain('v-dashboard-tiempo-respuesta-whatsapp');

    test()->getJson(route('admin.dashboard.stats', ['type' => 'tiempo-respuesta-whatsapp']))
        ->assertStatus(422);
});
