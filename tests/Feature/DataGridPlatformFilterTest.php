<?php

use Illuminate\Support\Facades\DB;
use Webkul\Whatsapp\Gateways\GatewayManager;
use Webkul\Whatsapp\Support\ContactChannel;

/**
 * The "Plataforma" filter shared by the Personas, Leads and Cotizaciones grids.
 *
 * These assert the SQL that gets BUILT, not rows that come back: the development
 * host cannot reach the database. That is not a workaround - every bug this
 * filter can have lives in the generated SQL (a missing table prefix, a join
 * that multiplies rows, a NULL that no dropdown value can ever match).
 */
function platformQuery(): Illuminate\Database\Query\Builder
{
    $query = DB::table('persons')->select('persons.id')->addSelect(ContactChannel::selectColumn());

    ContactChannel::joinTo($query, 'persons.id');

    return $query;
}

it('qualifies the joined alias with the table prefix', function () {
    // DB::table() applies DB_PREFIX on its own; raw SQL does not, and this
    // install runs with kl_. An unprefixed reference is a runtime SQL error that
    // no amount of PHP linting would catch.
    expect(DB::getTablePrefix())->not->toBe('');

    expect(platformQuery()->toSql())
        ->toContain(DB::getTablePrefix().'contact_channel.gateway');
});

it('keeps one row per contact so the listing cannot duplicate', function () {
    // Of the three grids only Leads has a GROUP BY. In the other two a join that
    // multiplied would show the same contact several times, raising no error at
    // all - it reads as bad data rather than bad code.
    $sql = platformQuery()->toSql();

    expect($sql)->toContain('ROW_NUMBER() OVER')
        ->and($sql)->toContain('PARTITION BY person_id')
        ->and($sql)->toContain('`rn` = ?');
});

it('resolves the channel to the active gateway, not merely the most recent', function () {
    $query = platformQuery();

    // The tie-break binding is the configured gateway: recency alone would
    // report a channel that was decommissioned months ago.
    expect($query->toSql())->toContain('ORDER BY (gateway = ?) DESC, last_message_at DESC')
        ->and($query->getBindings())->toContain((string) config('whatsapp.gateway'));
});

it('lets a contact with no conversation be filtered for', function () {
    // A dropdown builds `where(column, value)`, which never matches NULL. Without
    // the COALESCE, every hand-entered contact would be invisible the moment any
    // platform filter was applied.
    // Expression no longer casts to string on its own in Laravel 10; it needs
    // the grammar to render.
    $sql = ContactChannel::column()->getValue(DB::connection()->getQueryGrammar());

    expect($sql)->toContain('COALESCE')
        ->and($sql)->toContain("'".ContactChannel::NONE."'");
});

it('reads the dropdown options from the gateway registry', function () {
    $options = ContactChannel::options();
    $values = array_column($options, 'value');

    // Not hardcoded: plugging in a channel must make it filterable without
    // anyone remembering to edit three separate grids.
    foreach (app(GatewayManager::class)->keys() as $key) {
        expect($values)->toContain($key);
    }

    expect($values)->toContain(ContactChannel::NONE)
        ->and(end($options)['value'])->toBe(ContactChannel::NONE);
});

it('labels every value a cell can hold, including none and unknown', function () {
    expect(ContactChannel::label('cloud_api'))->toBe('WhatsApp')
        ->and(ContactChannel::label(ContactChannel::NONE))->toBe(trans('admin::app.common.no-channel'))
        // A contact whose row has no channel at all.
        ->and(ContactChannel::label(null))->toBe(trans('admin::app.common.no-channel'));
});

it('survives a filter value for a channel that no longer exists', function () {
    // A stale bookmark after a channel is unplugged must return an empty grid,
    // not a 500.
    $query = platformQuery()->where(ContactChannel::column(), 'un_canal_que_no_existe');

    expect($query->toSql())->toBeString()
        ->and($query->getBindings())->toContain('un_canal_que_no_existe');
});

it('builds the quotes grid with the platform column without breaking its query', function () {
    // The grid asks Bouncer who the viewer is. A global-permission user (never
    // saved) keeps the scoping branch out of the way without touching the DB.
    Illuminate\Support\Facades\Auth::guard('user')->setUser(
        (new Webkul\User\Models\User)->forceFill(['id' => 1, 'view_permission' => 'global'])
    );

    $grid = app(Webkul\Admin\DataGrids\Quote\QuoteDataGrid::class);

    $sql = $grid->prepareQueryBuilder()->toSql();

    expect($sql)->toContain('as platform')
        ->toContain(DB::getTablePrefix().'contact_channel.gateway')
        // The pre-existing joins must survive untouched.
        ->and($sql)->toContain('`kl_persons`')
        ->and($sql)->toContain('`kl_users`');
});

it('builds the persons grid with the platform column without breaking its query', function () {
    Illuminate\Support\Facades\Auth::guard('user')->setUser(
        (new Webkul\User\Models\User)->forceFill(['id' => 1, 'view_permission' => 'global'])
    );

    $sql = app(Webkul\Admin\DataGrids\Contact\PersonDataGrid::class)->prepareQueryBuilder()->toSql();

    expect($sql)->toContain('as platform')
        // The organizations join this grid already had must survive.
        ->toContain('`kl_organizations`');
});

/*
| LeadDataGrid is deliberately absent: its constructor resolves the default
| pipeline from the database, so it cannot be built on a host without one. That
| predates this work and is not something the platform filter introduced -
| it is covered by the manual checklist in tasks/todo.md instead.
*/

/*
|--------------------------------------------------------------------------
| The kanban board
|--------------------------------------------------------------------------
| The leads kanban does not go through the DataGrid: it hits a repository
| endpoint, so the same rule has to exist as a constraint. These pin that the
| two stay built on the same ranked subquery.
*/

function leadsFiltered(?string $value): Illuminate\Database\Query\Builder
{
    $query = DB::table('leads');

    ContactChannel::applyFilter($query, $value, 'leads.person_id');

    return $query;
}

it('leaves the query untouched when no channel is requested', function () {
    expect(leadsFiltered(null)->toSql())->toBe(DB::table('leads')->toSql())
        ->and(leadsFiltered('')->toSql())->toBe(DB::table('leads')->toSql());
});

it('filters the board through the same ranked subquery as the grids', function () {
    // If these two ever diverge, a contact reads as WhatsApp on the board and
    // Messenger on the grid, and nobody can tell which one is lying.
    $sql = leadsFiltered('cloud_api')->toSql();

    expect($sql)->toContain('ROW_NUMBER() OVER')
        ->and($sql)->toContain('PARTITION BY person_id')
        ->and(leadsFiltered('cloud_api')->getBindings())->toContain('cloud_api');
});

it('counts a lead with no contact at all as having no channel', function () {
    $sql = leadsFiltered(ContactChannel::NONE)->toSql();

    // Someone filtering for "sin canal" means to see these too; without the
    // null branch a lead with no person would vanish from every option.
    expect($sql)->toContain('`'.DB::getTablePrefix().'leads`.`person_id` is null')
        ->and($sql)->toContain('not in');
});

it('excludes null person ids from the not-in subquery', function () {
    // A NULL inside a NOT IN makes the whole predicate return nothing, so the
    // "sin canal" filter would come back empty for every lead.
    expect(leadsFiltered(ContactChannel::NONE)->toSql())
        ->toContain('`person_id` is not null');
});
