<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webkul\Whatsapp\Exceptions\HandoffConflictException;
use Webkul\Whatsapp\Jobs\NotifyHandoff;
use Webkul\Whatsapp\Mail\HandoffRequested;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Services\Handoff;

/**
 * The suite has no dedicated test database — phpunit.xml does not override the
 * connection — so every test here rolls back instead of leaving rows behind.
 */
uses(DatabaseTransactions::class);

/*
| Estos tests cubren el pozo y el ruteo por dueño SIN asesor por defecto. Con
| asesor por defecto ni turno sin ciudad (en producción nada cae al pozo); ese
| caso lo cubre tests/Feature/Product/DerivacionAsesorTest.php.
*/
beforeEach(fn () => config(['cotizacion.asesor_por_defecto' => null, 'cotizacion.turno_sin_ciudad' => false]));

function handoffService(): Handoff
{
    return app(Handoff::class);
}

function makeRole(string $name): int
{
    return DB::table('roles')->insertGetId([
        'name'            => $name,
        'permission_type' => 'custom',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

/**
 * A role that is NOT in handoff.exclude_roles, so advisors built by default are
 * valid assignees. Reusing role #1 would silently make them administrators,
 * which the routing now refuses.
 */
function salesRoleId(): int
{
    // Looked up per call, never memoised: DatabaseTransactions rolls each test
    // back, so a cached id would point at a role that no longer exists.
    $excluded = array_filter((array) config('whatsapp.handoff.exclude_roles', []));

    $existing = DB::table('roles')
        ->when($excluded, fn ($q) => $q->whereNotIn('name', $excluded))
        ->value('id');

    return $existing ?? makeRole('Ventas Test '.uniqid());
}

function makeAdvisor(string $name, int $status = 1, ?int $roleId = null): int
{
    return DB::table('users')->insertGetId([
        'name'       => $name,
        'email'      => 'advisor'.uniqid().'@test.local',
        'password'   => bcrypt('secret'),
        'role_id'    => $roleId ?? salesRoleId(),
        'status'     => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makePerson(?int $ownerId = null): int
{
    return DB::table('persons')->insertGetId([
        'name'       => 'WA Contact '.uniqid(),
        'emails'     => json_encode([]),
        'user_id'    => $ownerId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makeLead(int $personId, ?int $ownerId = null): int
{
    return DB::table('leads')->insertGetId([
        'title'      => 'WA Lead '.uniqid(),
        'person_id'  => $personId,
        'user_id'    => $ownerId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A conversation with whatever links the test needs. `ai_enabled` starts on,
 * mirroring what ConversationResolver does for a fresh conversation.
 */
function makeConversation(array $attributes = []): Conversation
{
    return Conversation::create(array_merge([
        'gateway'    => (string) config('whatsapp.gateway'),
        'wa_phone'   => '+5917'.random_int(1000000, 9999999),
        'wa_name'    => 'Tester',
        'ai_enabled' => true,
        'status'     => 'open',
    ], $attributes));
}

/*
|--------------------------------------------------------------------------
| Routing
|--------------------------------------------------------------------------
*/

it('routes the handoff to the lead owner', function () {
    Queue::fake();

    $owner = makeAdvisor('Lead Owner');
    $person = makePerson();
    $lead = makeLead($person, $owner);

    $conversation = makeConversation(['person_id' => $person, 'lead_id' => $lead]);

    $result = handoffService()->request($conversation, 'Pide un asesor');

    expect($result['changed'])->toBeTrue()
        ->and($result['conversation']->handoff_state)->toBe(Conversation::HANDOFF_ASSIGNED)
        ->and($result['conversation']->assigned_user_id)->toBe($owner);
});

it('falls back to the contact owner when the lead has none', function () {
    Queue::fake();

    $owner = makeAdvisor('Person Owner');
    $person = makePerson($owner);
    $lead = makeLead($person, null);

    $conversation = makeConversation(['person_id' => $person, 'lead_id' => $lead]);

    $result = handoffService()->request($conversation);

    expect($result['conversation']->assigned_user_id)->toBe($owner);
});

it('leaves the handoff in the pool when nobody owns the contact', function () {
    Queue::fake();

    $person = makePerson();
    $conversation = makeConversation(['person_id' => $person]);

    $result = handoffService()->request($conversation);

    expect($result['conversation']->handoff_state)->toBe(Conversation::HANDOFF_REQUESTED)
        ->and($result['conversation']->assigned_user_id)->toBeNull()
        ->and($result['conversation']->handoffPooled())->toBeTrue();
});

it('pools the handoff when the lead owner is a managing role', function () {
    Queue::fake();

    $adminRole = makeRole('Administrador '.uniqid());
    config(['whatsapp.handoff.exclude_roles' => [DB::table('roles')->where('id', $adminRole)->value('name')]]);

    // Most leads here are owned by an admin. Assigning to them would park the
    // conversation on someone who does not attend WhatsApp, and it would never
    // show up in the sales pool.
    $manager = makeAdvisor('Jefe dueño del lead', roleId: $adminRole);
    $person = makePerson();
    $lead = makeLead($person, $manager);

    $conversation = makeConversation(['person_id' => $person, 'lead_id' => $lead]);

    $result = handoffService()->request($conversation);

    expect($result['conversation']->assigned_user_id)->toBeNull()
        ->and($result['conversation']->handoffPooled())->toBeTrue();
});

it('pools the handoff when the owner is deactivated', function () {
    Queue::fake();

    // Otherwise it would sit "assigned" to a dead mailbox, invisible in the
    // pool, while the customer waits — and we tell the customer nothing.
    $owner = makeAdvisor('Gone Advisor', status: 0);
    $person = makePerson($owner);

    $conversation = makeConversation(['person_id' => $person]);

    $result = handoffService()->request($conversation);

    expect($result['conversation']->assigned_user_id)->toBeNull()
        ->and($result['conversation']->handoff_state)->toBe(Conversation::HANDOFF_REQUESTED);
});

it('always turns the AI agent off when deriving', function () {
    Queue::fake();

    $conversation = makeConversation(['ai_enabled' => true]);

    $result = handoffService()->request($conversation);

    expect($result['conversation']->ai_enabled)->toBeFalse()
        ->and($result['conversation']->aiEffective())->toBeFalse();
});

it('is idempotent: a second request neither re-routes nor re-notifies', function () {
    Queue::fake();

    $person = makePerson();
    $conversation = makeConversation(['person_id' => $person]);

    handoffService()->request($conversation, 'Primera');
    Queue::assertPushed(NotifyHandoff::class, 1);

    // An LLM will happily ask for a handoff on every single message.
    $second = handoffService()->request($conversation, 'Segunda');

    expect($second['changed'])->toBeFalse()
        ->and($second['conversation']->handoff_reason)->toBe('Primera');

    Queue::assertPushed(NotifyHandoff::class, 1);
});

it('derives a conversation with no person or lead without failing', function () {
    Queue::fake();

    $conversation = makeConversation();

    $result = handoffService()->request($conversation, 'Número desconocido');

    expect($result['changed'])->toBeTrue()
        ->and($result['conversation']->handoff_state)->toBe(Conversation::HANDOFF_REQUESTED)
        ->and($result['conversation']->handoff_reason)->toBe('Número desconocido');
});

it('records the handoff as a note on both the person and the lead', function () {
    Queue::fake();

    $person = makePerson();
    $lead = makeLead($person);
    $conversation = makeConversation(['person_id' => $person, 'lead_id' => $lead]);

    handoffService()->request($conversation, 'Quiere hablar con alguien');

    $activityId = DB::table('activities')
        ->where('type', 'note')
        ->where('comment', 'like', '%Quiere hablar con alguien%')
        ->orderByDesc('id')
        ->value('id');

    expect($activityId)->not->toBeNull()
        ->and(DB::table('person_activities')->where('activity_id', $activityId)->where('person_id', $person)->exists())->toBeTrue()
        ->and(DB::table('lead_activities')->where('activity_id', $activityId)->where('lead_id', $lead)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Concurrency
|--------------------------------------------------------------------------
*/

it('lets only one advisor claim a pooled conversation', function () {
    Queue::fake();

    $first = makeAdvisor('Fast');
    $second = makeAdvisor('Slow');

    $conversation = makeConversation();
    handoffService()->request($conversation);

    // Both advisors are looking at the same stale row: assigned_user_id is null
    // in memory for each of them. Only the WHERE clause can arbitrate.
    $stale = Conversation::find($conversation->id);

    handoffService()->claim($conversation, $first);

    expect(fn () => handoffService()->claim($stale, $second))
        ->toThrow(HandoffConflictException::class);

    expect(Conversation::find($conversation->id)->assigned_user_id)->toBe($first);
});

it('refuses to claim a conversation already assigned to someone', function () {
    Queue::fake();

    $owner = makeAdvisor('Owner');
    $other = makeAdvisor('Other');
    $person = makePerson($owner);

    $conversation = makeConversation(['person_id' => $person]);
    handoffService()->request($conversation);

    expect(fn () => handoffService()->claim($conversation, $other))
        ->toThrow(HandoffConflictException::class);
});

/*
|--------------------------------------------------------------------------
| Agent API (n8n)
|--------------------------------------------------------------------------
*/

it('rejects the handoff endpoint without a token', function () {
    $conversation = makeConversation();

    test()->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/handoff", ['reason' => 'x'])
        ->assertUnauthorized();
});

it('opens a handoff from the agent API', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();

    $conversation = makeConversation();

    test()->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/handoff", [
        'reason' => 'El cliente pide hablar con una persona',
    ])
        ->assertOk()
        ->assertJsonPath('handoff.state', Conversation::HANDOFF_REQUESTED)
        ->assertJsonPath('handoff.open', true)
        ->assertJsonPath('handoff.source', 'ai')
        ->assertJsonPath('handoff.changed', true)
        ->assertJsonPath('agent.effective', false);
});

it('answers a repeated handoff with 200 and changed=false, never 409', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();

    $conversation = makeConversation();

    test()->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/handoff", ['reason' => 'uno'])->assertOk();

    // A 409 here would send the n8n workflow into a retry loop.
    test()->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/handoff", ['reason' => 'dos'])
        ->assertOk()
        ->assertJsonPath('handoff.changed', false);
});

it('stops the agent from replying once the conversation was derived', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();

    $conversation = makeConversation(['last_inbound_at' => now()]);

    test()->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/handoff", ['reason' => 'derivar'])->assertOk();

    // The agent's reply can be in flight when the handoff lands: CRM → n8n →
    // back here. Without this guard the AI talks over the advisor.
    test()->postJson("/api/v1/whatsapp/conversations/{$conversation->id}/messages", ['text' => 'Hola, soy la IA'])
        ->assertStatus(409)
        ->assertJsonPath('handoff.open', true);

    expect(DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->count())->toBe(0);
});

it('exposes the handoff state in the agent context endpoint', function () {
    Queue::fake();
    actingAsSanctumAuthenticatedAdmin();

    $conversation = makeConversation();
    handoffService()->request($conversation, 'contexto');

    test()->getJson("/api/v1/whatsapp/conversations/{$conversation->id}/messages")
        ->assertOk()
        ->assertJsonPath('handoff.state', Conversation::HANDOFF_REQUESTED)
        ->assertJsonPath('handoff.reason', 'contexto');
});

/*
|--------------------------------------------------------------------------
| Admin endpoints
|--------------------------------------------------------------------------
*/

it('will not switch the AI back on while a handoff is open', function () {
    Queue::fake();
    test()->actingAs(getDefaultAdmin());

    $conversation = makeConversation();
    handoffService()->request($conversation);

    test()->patchJson(route('admin.whatsapp.agent', $conversation->id), ['enabled' => true])
        ->assertStatus(422)
        ->assertJsonPath('handoff.open', true);

    expect(Conversation::find($conversation->id)->ai_enabled)->toBeFalse();
});

it('still allows switching the AI off with a handoff open', function () {
    Queue::fake();
    test()->actingAs(getDefaultAdmin());

    $conversation = makeConversation();
    handoffService()->request($conversation);

    test()->patchJson(route('admin.whatsapp.agent', $conversation->id), ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('agent.effective', false);
});

it('claims a pooled conversation from the inbox', function () {
    Queue::fake();
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    $conversation = makeConversation();
    handoffService()->request($conversation);

    test()->postJson(route('admin.whatsapp.handoff.claim', $conversation->id))
        ->assertOk()
        ->assertJsonPath('handoff.state', Conversation::HANDOFF_ASSIGNED)
        ->assertJsonPath('handoff.is_mine', true);

    expect(Conversation::find($conversation->id)->assigned_user_id)->toBe($admin->id);
});

it('returns 409 when claiming a conversation someone else took', function () {
    Queue::fake();
    test()->actingAs(getDefaultAdmin());

    $other = makeAdvisor('Someone Else');
    $conversation = makeConversation();
    handoffService()->request($conversation);
    handoffService()->claim($conversation, $other);

    test()->postJson(route('admin.whatsapp.handoff.claim', $conversation->id))
        ->assertStatus(409)
        ->assertJsonPath('handoff.is_mine', false);
});

it('hands the conversation back to the agent when the handoff is closed', function () {
    Queue::fake();
    test()->actingAs(getDefaultAdmin());

    $conversation = makeConversation();
    handoffService()->request($conversation);

    test()->postJson(route('admin.whatsapp.handoff.resolve', $conversation->id), ['reactivate_ai' => true])
        ->assertOk()
        ->assertJsonPath('handoff.state', Conversation::HANDOFF_RESOLVED)
        ->assertJsonPath('handoff.open', false)
        ->assertJsonPath('agent.effective', true);

    // And the guard is gone: the toggle works again.
    test()->patchJson(route('admin.whatsapp.agent', $conversation->id), ['enabled' => true])->assertOk();
});

it('keeps the agent off when the advisor unticks the box', function () {
    Queue::fake();
    test()->actingAs(getDefaultAdmin());

    $conversation = makeConversation();
    handoffService()->request($conversation);

    test()->postJson(route('admin.whatsapp.handoff.resolve', $conversation->id), ['reactivate_ai' => false])
        ->assertOk()
        ->assertJsonPath('agent.effective', false);
});

it('claims a pooled conversation when an advisor simply writes in it', function () {
    Queue::fake();
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    $person = makePerson();
    $conversation = makeConversation(['person_id' => $person, 'last_inbound_at' => now()]);
    handoffService()->request($conversation);

    test()->postJson(route('admin.whatsapp.send'), [
        'phone' => $conversation->wa_phone,
        'text'  => 'Hola, te ayudo yo',
    ])->assertOk();

    // Otherwise the pool would keep advertising it as unattended.
    expect(Conversation::find($conversation->id)->assigned_user_id)->toBe($admin->id);
});

/*
|--------------------------------------------------------------------------
| thread() / polling
|--------------------------------------------------------------------------
*/

it('returns the handoff block with the advisor name resolved', function () {
    Queue::fake();
    test()->actingAs(getDefaultAdmin());

    $advisor = makeAdvisor('Juana Pérez');
    $conversation = makeConversation();
    handoffService()->request($conversation);
    handoffService()->claim($conversation, $advisor);

    test()->getJson(route('admin.whatsapp.thread', ['conversation_id' => $conversation->id]))
        ->assertOk()
        ->assertJsonPath('handoff.assigned_user.name', 'Juana Pérez')
        ->assertJsonPath('handoff.open', true);
});

it('still reports the handoff on an incremental poll with no new messages', function () {
    Queue::fake();
    test()->actingAs(getDefaultAdmin());

    $conversation = makeConversation();
    handoffService()->request($conversation, 'motivo');

    // A handoff creates no message, so if the block were tied to the `since`
    // cursor the banner would never appear until someone wrote something.
    test()->getJson(route('admin.whatsapp.thread', [
        'conversation_id' => $conversation->id,
        'since'           => now()->addMinute()->toDateTimeString(),
    ]))
        ->assertOk()
        ->assertJsonPath('messages', [])
        ->assertJsonPath('handoff.reason', 'motivo');
});

/*
|--------------------------------------------------------------------------
| Conversation list filters
|--------------------------------------------------------------------------
*/

it('filters the conversation list by pool and by mine', function () {
    Queue::fake();
    $admin = getDefaultAdmin();
    test()->actingAs($admin);

    $pooled = makeConversation();
    handoffService()->request($pooled);

    $mine = makeConversation();
    handoffService()->request($mine);
    handoffService()->claim($mine, $admin->id);

    $untouched = makeConversation();

    $ids = fn (string $filter) => collect(
        test()->getJson(route('admin.whatsapp.conversations', ['filter' => $filter]))->assertOk()->json('data')
    )->pluck('id')->all();

    expect($ids('pool'))->toContain($pooled->id)
        ->and($ids('pool'))->not->toContain($mine->id)
        ->and($ids('pool'))->not->toContain($untouched->id);

    expect($ids('mine'))->toContain($mine->id)
        ->and($ids('mine'))->not->toContain($pooled->id);

    expect($ids('handoff'))->toContain($pooled->id)
        ->and($ids('handoff'))->toContain($mine->id);
});

it('combines the pool filter with search and paging without breaking the query', function () {
    Queue::fake();
    test()->actingAs(getDefaultAdmin());

    $conversation = makeConversation(['wa_name' => 'Buscable '.uniqid()]);
    handoffService()->request($conversation);

    // The list query joins a ROW_NUMBER() subquery carrying a positional
    // binding; an unqualified column or a misplaced where breaks it silently.
    test()->getJson(route('admin.whatsapp.conversations', [
        'filter' => 'pool',
        'search' => 'Buscable',
        'page'   => 1,
    ]))
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'last_page']);
});

/*
|--------------------------------------------------------------------------
| Notification
|--------------------------------------------------------------------------
*/

it('emails the assigned advisor', function () {
    Mail::fake();

    $owner = makeAdvisor('Mailed Advisor');
    $person = makePerson($owner);
    $conversation = makeConversation(['person_id' => $person]);

    handoffService()->request($conversation);

    Mail::assertSent(HandoffRequested::class);
});

it('emails the active CRM users when nobody owns the contact', function () {
    Mail::fake();

    $salesRole = makeRole('Ventas '.uniqid());

    config([
        'whatsapp.handoff.pool_emails'      => [],
        'whatsapp.handoff.pool_group_id'    => null,
        'whatsapp.handoff.exclude_user_ids' => [],
        'whatsapp.handoff.exclude_roles'    => [],
    ]);

    $advisor = makeAdvisor('Pool Advisor', roleId: $salesRole);
    $email = DB::table('users')->where('id', $advisor)->value('email');

    $conversation = makeConversation();
    handoffService()->request($conversation);

    // The address is the one they log in with — no second list to maintain.
    Mail::assertSent(HandoffRequested::class, fn ($mail) => $mail->hasTo($email));
});

it('leaves managing roles out of the pool', function () {
    Mail::fake();

    $adminRole = makeRole('Administrador '.uniqid());
    $salesRole = makeRole('Ventas '.uniqid());

    config([
        'whatsapp.handoff.pool_emails'      => [],
        'whatsapp.handoff.pool_group_id'    => null,
        'whatsapp.handoff.exclude_user_ids' => [],
        'whatsapp.handoff.exclude_roles'    => [DB::table('roles')->where('id', $adminRole)->value('name')],
    ]);

    $manager = makeAdvisor('Jefa', roleId: $adminRole);
    $seller = makeAdvisor('Vendedor', roleId: $salesRole);

    $managerEmail = DB::table('users')->where('id', $manager)->value('email');
    $sellerEmail = DB::table('users')->where('id', $seller)->value('email');

    handoffService()->request(makeConversation());

    // Answering an unclaimed conversation is not a manager's job.
    Mail::assertSent(HandoffRequested::class, fn ($mail) => $mail->hasTo($sellerEmail) && ! $mail->hasTo($managerEmail));
});

it('skips deactivated and excluded users in the pool', function () {
    Mail::fake();

    $salesRole = makeRole('Ventas '.uniqid());

    $inactive = makeAdvisor('Inactive Advisor', status: 0, roleId: $salesRole);
    $excluded = makeAdvisor('Bot Account', roleId: $salesRole);
    makeAdvisor('Vendedora activa', roleId: $salesRole);

    config([
        'whatsapp.handoff.pool_emails'      => [],
        'whatsapp.handoff.pool_group_id'    => null,
        'whatsapp.handoff.exclude_roles'    => [],
        'whatsapp.handoff.exclude_user_ids' => [$excluded],
    ]);

    $inactiveEmail = DB::table('users')->where('id', $inactive)->value('email');
    $excludedEmail = DB::table('users')->where('id', $excluded)->value('email');

    handoffService()->request(makeConversation());

    Mail::assertSent(HandoffRequested::class, fn ($mail) => ! $mail->hasTo($inactiveEmail) && ! $mail->hasTo($excludedEmail));
});

it('restricts the pool to a user group when one is configured', function () {
    Mail::fake();

    $salesRole = makeRole('Ventas '.uniqid());

    $inGroup = makeAdvisor('Grouped Advisor', roleId: $salesRole);
    $outside = makeAdvisor('Ungrouped Advisor', roleId: $salesRole);

    $groupId = DB::table('groups')->value('id');
    DB::table('user_groups')->insert(['user_id' => $inGroup, 'group_id' => $groupId]);

    config([
        'whatsapp.handoff.pool_emails'      => [],
        'whatsapp.handoff.pool_group_id'    => $groupId,
        'whatsapp.handoff.exclude_roles'    => [],
        'whatsapp.handoff.exclude_user_ids' => [],
    ]);

    $inEmail = DB::table('users')->where('id', $inGroup)->value('email');
    $outEmail = DB::table('users')->where('id', $outside)->value('email');

    handoffService()->request(makeConversation());

    Mail::assertSent(HandoffRequested::class, fn ($mail) => $mail->hasTo($inEmail) && ! $mail->hasTo($outEmail));
});

it('renders the handoff email', function () {
    Queue::fake();

    $person = makePerson();
    $conversation = makeConversation(['person_id' => $person]);
    handoffService()->request($conversation, 'Quiere cotizar por volumen');

    // Mail::fake() never renders the view, so without this the template could be
    // broken (bad helper, missing variable) and every test would still pass.
    $html = (new HandoffRequested($conversation->refresh(), false))->render();

    expect($html)->toContain('Quiere cotizar por volumen')
        ->and($html)->toContain('?conversation='.$conversation->id);
});

it('does not fail when there is nobody to email', function () {
    Mail::fake();

    // An empty group is the realistic way to end up with no recipients.
    config([
        'whatsapp.handoff.pool_emails'   => [],
        'whatsapp.handoff.pool_group_id' => DB::table('groups')->insertGetId([
            'name'       => 'Grupo vacío '.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ]);

    $result = handoffService()->request(makeConversation());

    // The handoff still happens; only the backup signal is missing.
    expect($result['changed'])->toBeTrue();
    Mail::assertNothingSent();
});
