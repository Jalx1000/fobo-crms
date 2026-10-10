<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        /**
         * Everything on the "api" middleware group. Machine traffic (Sanctum
         * personal access tokens — the AI agent, the mobile app) gets the
         * generous bucket; the rest keeps the conservative default.
         */
        RateLimiter::for('api', function (Request $request) {
            if ($user = $this->agentUser($request)) {
                return $this->agentLimit($user->id);
            }

            return Limit::perMinute(config('agent_api.default_rate_limit'))
                ->by($request->user()?->id ?: $request->ip());
        });

        /**
         * The same generous bucket, applied explicitly to the routes the agent
         * hammers, so they stay fast even if the shared "api" limiter is ever
         * tightened. Unauthenticated callers fall back to the default limit —
         * this must never become an open door.
         */
        RateLimiter::for('agent-api', function (Request $request) {
            if ($user = $this->agentUser($request)) {
                return $this->agentLimit($user->id);
            }

            return Limit::perMinute(config('agent_api.default_rate_limit'))
                ->by($request->ip());
        });

        /**
         * The mobile app: a person, not a machine. Polls the inbox every few
         * seconds, so it needs more than the browser default, but far less
         * than the agent — and each adviser gets their own bucket, so one
         * phone left open cannot starve the others.
         */
        RateLimiter::for('mobile-api', function (Request $request) {
            $user = $request->user('sanctum');

            return Limit::perMinute(config('agent_api.mobile_rate_limit'))
                ->by($user ? 'mobile-api:'.$user->id : $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * The user behind a Sanctum personal access token, when that user is
     * allowed the agent bucket.
     *
     * Resolved through the "sanctum" guard on purpose: the throttle middleware
     * runs before `auth:sanctum`, so the default guard has nobody yet and
     * `$request->user()` would be null for exactly the traffic we want to
     * recognise. The guard caches the user for the rest of the request.
     */
    protected function agentUser(Request $request)
    {
        if (! $request->bearerToken()) {
            return null;
        }

        $user = $request->user('sanctum');

        if (! $user) {
            return null;
        }

        $allowed = config('agent_api.users');

        if (empty($allowed)) {
            return $user;
        }

        return in_array((string) $user->id, $allowed, true)
            || in_array((string) $user->email, $allowed, true)
                ? $user
                : null;
    }

    /**
     * Per-user bucket, so one integration cannot exhaust another's quota.
     */
    protected function agentLimit(int $userId): Limit
    {
        return Limit::perMinute(config('agent_api.rate_limit'))->by('agent-api:'.$userId);
    }
}
