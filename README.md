# resonate-users

Signed-in user identity and direct messaging for [Resonate](https://github.com/webpatser/resonate).

A Resonate connection has no identity of its own. The only way the server learns who a socket belongs to is the `user_id` inside a presence channel's `channel_data`, so everything user-shaped is conditional on the client having joined a presence channel. `terminate_connections` silently misses anyone who never did, and there is no way at all to push a message to a person rather than to a room.

This plugin implements the Pusher protocol's user authentication instead. A client sends `pusher:signin` with a signed blob; the server verifies it and the socket carries an identity from then on, whether or not it ever joins a channel.

```
client                          Resonate + resonate-users
  |  pusher:signin {auth, user_data}  ->  verify HMAC
  |                                       remember user 42
  |                                       join #server-to-user-42
  |  <- pusher:signin_success
                                       ...
your app:  sendToUser(42, 'chat.dm', [...])
  |  <- chat.dm            (every device user 42 has, on every node)
```

## What it gives you

- **Direct messages.** Address a person, not a room. Every device that person has signed in on receives it, on every node.
- **Notifications for rooms a user is not watching.** They only have to be connected, not subscribed.
- **A working `terminate_connections`.** Forced logout after a password change reaches users who never joined a presence channel.
- **An online index.** Ask whether someone is connected, and on how many devices, without a metrics round-trip.

## The client half already exists

Laravel ships all of it. `Broadcast::userRoutes()` registers `/broadcasting/user-auth`, and `PusherBroadcaster::resolveAuthenticatedUser()` signs the blob with `pusher/pusher-php-server`. Echo and pusher-js already speak the flow. Until now nothing happened, because Resonate answered `pusher:signin` with "Unknown Pusher event".

```php
// routes/web.php or a service provider
Broadcast::userRoutes();

Broadcast::resolveAuthenticatedUserUsing(function ($request) {
    return $request->user()
        ? ['id' => (string) $request->user()->id, 'user_info' => ['name' => $request->user()->name]]
        : null;
});
```

## Installation

```bash
composer require webpatser/resonate-users
```

Register the plugin in `config/reverb.php`:

```php
'servers' => [
    'reverb' => [
        // ...
        'plugins' => [
            Webpatser\ResonateUsers\UserChannelPlugin::class,
        ],
    ],
],
```

Publish the config if you want to change the Redis connection, the key prefix or the heartbeat:

```bash
php artisan vendor:publish --tag=resonate-users-config
```

Restart the server (`php artisan resonate:start`, or `resonate:reload` for a zero-downtime swap).

## Sending to a user

A user channel is an ordinary channel name, so anything that can broadcast can reach a person. The Pusher SDK's own helper works:

```php
app('pusher')->sendToUser('42', 'chat.dm', ['body' => 'hello']);
```

So does a plain broadcast, which is useful when you already have a broadcaster configured:

```php
use Webpatser\ResonateUsers\UserRegistry;

$channel = app(UserRegistry::class)->channelFor('42');   // #server-to-user-42
```

Nothing new carries the message across nodes: the event dispatcher already publishes to every node, and each one delivers to the devices it holds.

## Asking who is online

```php
use Webpatser\ResonateUsers\UserRegistry;

$users = app(UserRegistry::class);

$users->isOnline('42');        // bool
$users->deviceCount('42');     // 3
$users->sockets('42');         // ['1234.5678', ...] across every node
$users->snapshot();            // ['42' => 3, '7' => 1] for the whole application
```

On a server with several applications, pass the id: `$users->isOnline('42', 'app-two')`.

## Events

| Event | When |
|-------|------|
| `UserSignedIn` | A connection proved an identity. Once per device, not once per person. |
| `UserSignInRejected` | A signin was refused. Carries a `reason` constant, so you can alert on a run of `INVALID_SIGNATURE` without matching on prose. |
| `UserWentOffline` | A user's last device disconnected anywhere in the cluster. |

`UserWentOffline` covers a quiet death as well as a clean goodbye: a socket that stops answering is pinged, pruned and closed by the server, and that close reaches the plugin like any other. It is not a "user is gone" guarantee across a reconnect, though. A client that drops and comes back produces an offline followed by a fresh sign-in, so hold the event for a grace period if you need a brief reconnect to pass unnoticed.

## Security

- **A direct subscribe to a user channel is always refused.** A user channel carries no `private-` or `presence-` prefix, so core would subscribe anyone who asked. Membership is granted by signin and by nothing else. Without this guard one client could read another's direct messages, which is worth knowing even if you do not install this plugin: an application calling the Pusher SDK's `sendToUser()` against a bare Resonate is broadcasting to a world-readable channel.
- **The signature covers the socket id**, so a payload lifted from one connection cannot be replayed onto another.
- **The key half of `auth` is checked against the connection's own application**, so a token minted for one tenant cannot be presented on another's socket.
- **The first valid signin wins.** A second one is refused rather than allowed to change a live socket's identity.
- **A user id never reaches a Redis key raw.** It arrives inside a blob the application signed, which proves the application vouched for it and says nothing about its shape, so colons and glob metacharacters are percent-encoded before they form a key.

## Configuration

| Key | Default | Purpose |
|-----|---------|---------|
| `connection` | `REDIS_*` env | Redis server holding the index. Takes Laravel's connection keys, `scheme` (`tcp`, `tls`/`rediss`, `unix`), `read_timeout` and the retry keys included. |
| `key_prefix` | `users` | Namespace for every index key. Avoid colons. |
| `ttl` | `90` | Seconds each node's key lives; refreshed on every heartbeat. |
| `heartbeat_interval` | `30` | Seconds between reconcile ticks. Keep it well below `ttl`. |
| `user_channels` | `true` | Whether a signed-in connection joins its own channel. Turning it off records identity but stops delivery. |

Override any of these per environment with `RESONATE_USERS_*` variables.

## How the index stays honest

Membership is written per node, at `{prefix}:{appId}:{userId}:{nodeId}`, so a node that dies lets its share expire by TTL rather than a live node holding it open. The lifecycle hooks make the fast incremental edits; the heartbeat tick is the authority, rewriting each tracked user's set from the connections this node actually holds. A lost removal therefore self-heals instead of reporting a signed-out person as online forever.

The index is never consulted when sending. That path is a channel broadcast, which the server already fans out correctly.

## Other plugins

If you also run [`webpatser/resonate-webhooks`](https://github.com/webpatser/resonate-webhooks) or [`webpatser/resonate-channel-meter`](https://github.com/webpatser/resonate-channel-meter), use versions that ignore `#`-prefixed channels. Otherwise every sign-in posts a `channel_occupied` and, in the meter's case, opens a billable period for what is really a user's session.

## Requirements

- PHP 8.5+
- Resonate 0.6.2+
- A Redis server reachable from every Resonate node

## Testing

```bash
composer test
```

The integration suite skips itself unless a Redis server answers on `127.0.0.1:6379`.

## License

MIT.
