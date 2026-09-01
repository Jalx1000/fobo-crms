<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The AI agent shares /api with the admin UI. Before this, both lived in
 * Laravel's default 60/min bucket and one customer turn — catalogue, warehouses,
 * leads, then the reply POST — could exhaust it, so the customer got silence.
 *
 * The suite runs against a live database, so every token this creates is rolled
 * back.
 */
beforeEach(function () {
    Cache::flush(); // rate limiter state is cache-backed
    DB::beginTransaction();
});

afterEach(fn () => DB::rollBack());

/**
 * A real personal access token, sent as a real Bearer header: that header is
 * exactly what the limiter keys the agent bucket off.
 */
function agentToken(): string
{
    return getDefaultAdmin()->createToken('test-agent')->plainTextToken;
}

it('lets the agent burst well past the default api limit', function () {
    $token = agentToken();

    // Twelve back-to-back calls: what one customer turn actually costs.
    for ($i = 0; $i < 12; $i++) {
        $response = test()->withToken($token)->getJson('/api/v1/leads');

        expect($response->status())->not->toBe(429, "request #{$i} was throttled");
    }
});

it('keeps the agent and the browser UI in separate buckets', function () {
    config(['agent_api.rate_limit' => 5, 'agent_api.default_rate_limit' => 5]);

    $token = agentToken();

    foreach (range(1, 5) as $i) {
        test()->withToken($token)->getJson('/api/v1/leads');
    }

    // The agent has spent its bucket...
    test()->withToken($token)->getJson('/api/v1/leads')->assertStatus(429);

    // ...which must not have cost the non-agent traffic anything: a request
    // without the Bearer header lands in the per-IP bucket, still untouched.
    // flushHeaders() because withToken() sticks to the test case.
    $response = test()->flushHeaders()->getJson('/api/v1/leads');

    expect($response->status())->not->toBe(429);
});

it('answers a throttled request with Retry-After so the caller can back off', function () {
    config(['agent_api.rate_limit' => 2]);

    $token = agentToken();

    test()->withToken($token)->getJson('/api/v1/leads');
    test()->withToken($token)->getJson('/api/v1/leads');

    $response = test()->withToken($token)->getJson('/api/v1/leads');

    $response->assertStatus(429);

    expect($response->headers->get('Retry-After'))->not->toBeNull()
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);

    expect($response->headers->get('X-RateLimit-Limit'))->toBe('2');
});

it('applies the agent bucket to the whatsapp reply endpoint', function () {
    config(['agent_api.rate_limit' => 600]);

    $token = agentToken();

    // 404 (no such conversation) is fine — what matters is that twelve rapid
    // POSTs to the reply endpoint never hit the throttle.
    for ($i = 0; $i < 12; $i++) {
        $response = test()->withToken($token)
            ->postJson('/api/v1/whatsapp/conversations/999999999/messages', ['text' => 'hola']);

        expect($response->status())->not->toBe(429, "reply #{$i} was throttled");
    }
});
