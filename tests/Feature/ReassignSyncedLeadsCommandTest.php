<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * El comando leads:reassign-synced-to-unassigned reasigna a la cuenta
 * "Sin asignar" SOLO los leads que vienen de la sync (están en
 * smd_synced_events) y hoy pertenecen a un admin. Los leads creados a mano por
 * un admin NO se tocan.
 */
beforeEach(function () {
    $this->personId = DB::table('persons')->value('id');

    $this->unassigned = DB::table('users')
        ->where('email', config('dashboard.unassigned_user.email'))
        ->first();

    $this->admin = User::create([
        'name'     => 'Admin '.uniqid(),
        'email'    => 'admin'.uniqid().'@test.com',
        'password' => bcrypt('secret'),
        'role_id'  => 1, // Administrator
        'status'   => 1,
    ]);

    // Lead "de la sync": pertenece al admin y está enlazado en smd_synced_events.
    $this->syncLeadId = DB::table('leads')->insertGetId([
        'title'                  => 'Sync lead '.uniqid(),
        'user_id'                => $this->admin->id,
        'person_id'              => $this->personId,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => 1,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    DB::table('smd_synced_events')->insert([
        'external_id' => 'test-'.uniqid(),
        'lead_id'     => $this->syncLeadId,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    // Lead "manual" del admin: NO está en smd_synced_events -> no debe tocarse.
    $this->manualLeadId = DB::table('leads')->insertGetId([
        'title'                  => 'Manual lead '.uniqid(),
        'user_id'                => $this->admin->id,
        'person_id'              => $this->personId,
        'lead_pipeline_id'       => 1,
        'lead_pipeline_stage_id' => 1,
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
});

it('dry-run no modifica nada', function () {
    Artisan::call('leads:reassign-synced-to-unassigned');

    expect((int) DB::table('leads')->where('id', $this->syncLeadId)->value('user_id'))
        ->toBe((int) $this->admin->id);
    expect(DB::table('lead_reassignment_log')->where('lead_id', $this->syncLeadId)->exists())
        ->toBeFalse();
});

it('--apply reasigna el lead de la sync, deja el manual y registra auditoría', function () {
    Artisan::call('leads:reassign-synced-to-unassigned', ['--apply' => true]);

    // El lead de la sync pasó a "Sin asignar".
    expect((int) DB::table('leads')->where('id', $this->syncLeadId)->value('user_id'))
        ->toBe((int) $this->unassigned->id);

    // El lead manual del admin quedó intacto.
    expect((int) DB::table('leads')->where('id', $this->manualLeadId)->value('user_id'))
        ->toBe((int) $this->admin->id);

    // Quedó registrada la auditoría con el dueño previo.
    $log = DB::table('lead_reassignment_log')->where('lead_id', $this->syncLeadId)->first();
    expect($log)->not->toBeNull();
    expect((int) $log->old_user_id)->toBe((int) $this->admin->id);
    expect((int) $log->new_user_id)->toBe((int) $this->unassigned->id);
});

it('--rollback --apply restaura el dueño previo', function () {
    Artisan::call('leads:reassign-synced-to-unassigned', ['--apply' => true]);
    Artisan::call('leads:reassign-synced-to-unassigned', ['--rollback' => true, '--apply' => true]);

    expect((int) DB::table('leads')->where('id', $this->syncLeadId)->value('user_id'))
        ->toBe((int) $this->admin->id);
    expect(DB::table('lead_reassignment_log')->where('lead_id', $this->syncLeadId)->exists())
        ->toBeFalse();
});
