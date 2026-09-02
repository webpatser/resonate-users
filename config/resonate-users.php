<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redis connection
    |--------------------------------------------------------------------------
    |
    | Where the user index lives: a per-node set of socket ids per user, so the
    | host application can ask whether someone is connected without a metrics
    | round-trip. Both sides use this one block, the plugin inside Resonate over
    | the fledge-fiber async client and the UserRegistry over predis from your
    | Laravel app, so keep it as one source of truth.
    |
    | The block takes Laravel's own Redis connection keys and is handed to
    | fledge-fiber verbatim, so `scheme` (tcp, tls/rediss, unix), `read_timeout`,
    | `name`, `tcp_keepalive` and the retry keys all work here.
    |
    */

    'connection' => [
        'url' => env('RESONATE_USERS_REDIS_URL', env('REDIS_URL')),
        'scheme' => env('RESONATE_USERS_REDIS_SCHEME', env('REDIS_SCHEME', 'tcp')),
        'host' => env('RESONATE_USERS_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
        'port' => env('RESONATE_USERS_REDIS_PORT', env('REDIS_PORT', '6379')),
        'username' => env('RESONATE_USERS_REDIS_USERNAME', env('REDIS_USERNAME')),
        'password' => env('RESONATE_USERS_REDIS_PASSWORD', env('REDIS_PASSWORD')),
        'database' => env('RESONATE_USERS_REDIS_DB', env('REDIS_DB', '0')),
        'timeout' => env('RESONATE_USERS_REDIS_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Key prefix
    |--------------------------------------------------------------------------
    |
    | Namespace for every index key. Avoid colons in the prefix; the schema
    | splits keys on them.
    |
    */

    'key_prefix' => env('RESONATE_USERS_KEY_PREFIX', 'users'),

    /*
    |--------------------------------------------------------------------------
    | TTL and heartbeat
    |--------------------------------------------------------------------------
    |
    | Each node's key carries `ttl` seconds and is refreshed on every heartbeat,
    | so a node that dies without cleaning up lets its entries expire instead of
    | reporting its users as online forever. Keep `heartbeat_interval` well
    | below `ttl`.
    |
    */

    'ttl' => (int) env('RESONATE_USERS_TTL', 90),

    'heartbeat_interval' => (float) env('RESONATE_USERS_HEARTBEAT_INTERVAL', 30),

    /*
    |--------------------------------------------------------------------------
    | User channels
    |--------------------------------------------------------------------------
    |
    | Whether a signed-in connection joins its own `#server-to-user-{id}`
    | channel. This is what makes `sendToUser()` and `terminate_connections`
    | work, so leave it on unless you only want identity recorded and intend to
    | route messages some other way.
    |
    | A direct subscribe to a user channel is always refused, whatever this is
    | set to: membership is granted by signin and nothing else.
    |
    */

    'user_channels' => (bool) env('RESONATE_USERS_USER_CHANNELS', true),

];
