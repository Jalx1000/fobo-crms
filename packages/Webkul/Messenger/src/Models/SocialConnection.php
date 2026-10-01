<?php

namespace Webkul\Messenger\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Webkul\Messenger\Contracts\SocialConnection as SocialConnectionContract;

/**
 * A connected social account — for Messenger, one Facebook page: its id and the
 * page access token replies are sent with.
 *
 * Credentials live in the database rather than the environment because a tenant
 * may connect several pages, and each carries its own long-lived token.
 * Connect one with `php artisan messenger:connect-page`.
 */
class SocialConnection extends Model implements SocialConnectionContract
{
    protected $table = 'social_connections';

    protected $fillable = [
        'channel',
        'account_id',
        'name',
        'access_token',
        'settings',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'settings'     => 'array',
    ];

    /**
     * Never leak a page token into a log line, an exception render or a
     * queued job payload.
     */
    protected $hidden = ['access_token'];

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel)->orderBy('id');
    }
}
