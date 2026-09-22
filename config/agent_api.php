<?php

/*
|--------------------------------------------------------------------------
| API rate limiting
|--------------------------------------------------------------------------
| The WhatsApp AI agent talks to this CRM over the same /api surface a human
| would, but at machine pace: one customer turn fans out into a catalogue read,
| a warehouse read, one or more lead calls and finally the reply POST. Sharing
| Laravel's default 60/min bucket with the browser UI meant a single turn could
| exhaust it and the customer got silence instead of an answer.
|
| So requests authenticated with a Sanctum personal access token (only machines
| hold one — the admin UI authenticates by session) get their own, generous
| bucket, keyed per user so one integration cannot starve another.
*/

return [
    /*
    | Requests/minute for token-authenticated (agent) traffic.
    */
    'rate_limit' => (int) env('AGENT_API_RATE_LIMIT', 600),

    /*
    | Requests/minute for everything else on the "api" middleware group.
    */
    'default_rate_limit' => (int) env('API_RATE_LIMIT', 60),

    /*
    | Restrict the generous bucket to specific users, by id or by email:
    |
    |     AGENT_API_USERS=13
    |     AGENT_API_USERS=kohlberg@sofopolis.com,ops@example.com
    |
    | Empty (the default) means every token-authenticated user qualifies.
    */
    'users' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AGENT_API_USERS', ''))
    ))),
];
