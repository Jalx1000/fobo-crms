<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\SmdAppointmentSyncService;

/**
 * `SmdStatusMapper::getTag()` existía desde el principio pero no lo llamaba
 * nadie: el único tag que se aplicaba era el 'Cancelado' hardcodeado en
 * cancelDropbox(). Una cita SURGERY quedaba en la etapa Cirugía pero sin tag.
 *
 * Estos tests cubren que el tag siga al status en las tres entradas (alta,
 * actualización y cancelación) y -lo importante- que se SAQUE cuando el status
 * deja de corresponder, sin tocar los tags puestos a mano.
 */
uses(DatabaseTransactions::class);

beforeEach(function () {
    Cache::flush();

    $stageId = DB::table('lead_pipeline_stages')->value('id') ?? 1;

    config([
        'smd.dropbox.access_token'        => 'fake-access-token',
        'smd.dropbox.refresh_token'       => null,
        'smd.dropbox.app_key'             => null,
        'smd.dropbox.app_secret'          => null,
        'smd.dropbox.appointments_folder' => '/smd-events',
        'smd.stage_map.confirmed'         => $stageId,
        'smd.stage_map.completed'         => $stageId,
        'smd.stage_map.cancelled'         => $stageId,
        'smd.stage_map.no_show'           => $stageId,
        'smd.stage_map.surgery'           => $stageId,
        'smd.stage_map.default'           => $stageId,
    ]);
});

/**
 * Sincroniza un evento con el status dado y devuelve los nombres de tag del lead.
 *
 * @return array{tags: array<int, string>, lead_id: int}
 */
function sincronizarConStatus(string $id, string $status, string $hash, string $updatedAt): array
{
    $payload = smdEvent($id, [
        'summary'    => 'exodoncia pza 4.6',
        'status'     => $status,
        'updated_at' => $updatedAt,
    ]);

    $file = fileEntry('2026-07-27', '1331', $id, $hash);

    fakeDropbox(['2026-07-27' => [$file]], [$file['path_lower'] => $payload]);

    app(SmdAppointmentSyncService::class)->run(1, null, \Carbon\Carbon::parse('2026-07-27'));

    $leadId = DB::table('smd_synced_events')->where('external_id', $id)->value('lead_id');

    return [
        'lead_id' => $leadId,
        'tags'    => DB::table('lead_tags')
            ->join('tags', 'tags.id', '=', 'lead_tags.tag_id')
            ->where('lead_tags.lead_id', $leadId)
            ->pluck('tags.name')
            ->sort()
            ->values()
            ->all(),
    ];
}

// ---------------------------------------------------------------------------

it('una cita SURGERY nueva nace con el tag Cirugía', function () {
    $r = sincronizarConStatus('ev-'.uniqid(), 'SURGERY', 'h1', '2026-07-27T13:31:17.000Z');

    expect($r['tags'])->toBe(['Cirugía']);
});

it('una cita sin status no recibe ningún tag', function () {
    $r = sincronizarConStatus('ev-'.uniqid(), '', 'h1', '2026-07-27T13:31:17.000Z');

    expect($r['tags'])->toBe([]);
});

it('el tag sigue al status cuando la cita se actualiza', function () {
    $id = 'ev-'.uniqid();

    expect(sincronizarConStatus($id, '', 'h1', '2026-07-27T13:31:17.000Z')['tags'])->toBe([]);

    // Mismo caso real que motivó todo: la edición pasa la cita a SURGERY.
    expect(sincronizarConStatus($id, 'SURGERY', 'h2', '2026-07-27T13:31:25.000Z')['tags'])
        ->toBe(['Cirugía']);
});

it('el tag anterior se saca cuando el status deja de corresponder', function () {
    $id = 'ev-'.uniqid();

    expect(sincronizarConStatus($id, 'MISSED', 'h1', '2026-07-27T13:31:17.000Z')['tags'])
        ->toBe(['Cita perdida']);

    // La cita se reprograma y deja de estar perdida: no puede quedar marcada.
    $r = sincronizarConStatus($id, '', 'h2', '2026-07-27T13:31:25.000Z');

    expect($r['tags'])->toBe([]);
});

it('no acumula tags al pasar de un status con tag a otro', function () {
    $id = 'ev-'.uniqid();

    sincronizarConStatus($id, 'MISSED', 'h1', '2026-07-27T13:31:17.000Z');
    $r = sincronizarConStatus($id, 'SURGERY', 'h2', '2026-07-27T13:31:25.000Z');

    expect($r['tags'])->toBe(['Cirugía']);
});

it('no toca los tags que puso una persona a mano', function () {
    $id = 'ev-'.uniqid();

    $r = sincronizarConStatus($id, 'MISSED', 'h1', '2026-07-27T13:31:17.000Z');

    $tagManual = DB::table('tags')->insertGetId([
        'name'       => 'VIP '.uniqid(),
        'color'      => '#000000',
        'user_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('lead_tags')->insert(['lead_id' => $r['lead_id'], 'tag_id' => $tagManual]);

    $nombreManual = DB::table('tags')->where('id', $tagManual)->value('name');

    $r = sincronizarConStatus($id, 'SURGERY', 'h2', '2026-07-27T13:31:25.000Z');

    expect($r['tags'])->toContain('Cirugía')
        ->and($r['tags'])->toContain($nombreManual)
        ->and($r['tags'])->not->toContain('Cita perdida');
});

it('una cancelación deja el tag Cancelado y saca el anterior', function () {
    $id = 'ev-'.uniqid();

    sincronizarConStatus($id, 'SURGERY', 'h1', '2026-07-27T13:31:17.000Z');

    $r = sincronizarConStatus($id, 'CANCELED', 'h2', '2026-07-27T13:31:25.000Z');

    expect($r['tags'])->toBe(['Cancelado']);
});
