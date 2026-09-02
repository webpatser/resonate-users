<?php

namespace Webpatser\ResonateUsers;

use Fledge\Async\Redis\RedisConfig;
use JsonException;
use Throwable;
use Webpatser\Resonate\Contracts\Connection;
use Webpatser\Resonate\Loggers\Log;
use Webpatser\Resonate\Plugins\Contracts\ConnectionLifecycle;
use Webpatser\Resonate\Plugins\Contracts\MessageInterceptor;
use Webpatser\Resonate\Plugins\Contracts\ServerPlugin;
use Webpatser\Resonate\Plugins\Contracts\TickScheduler;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Protocols\Pusher\Channels\Channel;
use Webpatser\Resonate\Protocols\Pusher\EventHandler;
use Webpatser\ResonateUsers\Events\UserSignedIn;
use Webpatser\ResonateUsers\Events\UserSignInRejected;
use Webpatser\ResonateUsers\Events\UserWentOffline;

use function Fledge\Async\Redis\createRedisClient;

/**
 * Gives a connection a user identity, and a channel of its own.
 *
 * Resonate on its own only learns who a socket belongs to from the `user_id`
 * inside a presence channel's `channel_data`, so every user-shaped feature is
 * conditional on the client having joined a presence channel. This plugin
 * implements the Pusher protocol's user authentication instead: a client sends
 * `pusher:signin` with a signed `user_data` blob, and from then on the socket
 * carries an identity whether or not it ever joins a channel.
 *
 * A signed-in connection is subscribed to its own channel,
 * `#server-to-user-{id}`, which is what `pusher/pusher-php-server`'s
 * `sendToUser()` triggers on. Sending therefore needs nothing new: an ordinary
 * broadcast to that channel already reaches every one of the user's devices, on
 * every node, through the existing event dispatcher.
 *
 * Subscribing the connection with `channel_data` naming the user also repairs
 * `POST /apps/{id}/users/{userId}/terminate_connections`, on this node and
 * across the cluster, because both paths match on the `user_id` recorded
 * against a channel connection.
 *
 * The cluster-wide index in Redis ({@see UserDeviceIndex}) is not on the send
 * path. It answers "is this person connected, on how many devices" for the host
 * application.
 */
class UserChannelPlugin implements ConnectionLifecycle, MessageInterceptor, ServerPlugin, TickScheduler
{
    /**
     * The connection state key holding the signed-in user id.
     */
    public const string USER_ID = 'user.id';

    /**
     * The connection state key holding the signed-in user's info blob.
     */
    public const string USER_INFO = 'user.info';

    /**
     * The server API surface handed in at boot.
     */
    protected PluginContext $context;

    /**
     * The cluster-wide device index.
     */
    protected ?UserDeviceIndex $index = null;

    /**
     * The index key schema.
     */
    protected UserKeys $keys;

    /**
     * Seconds between heartbeat ticks.
     */
    protected float $heartbeat = 30.0;

    /**
     * Whether a signed-in connection joins its own user channel.
     */
    protected bool $joinsUserChannel = true;

    /**
     * The connections this node has signed in: appId => userId => socketId => connection.
     *
     * What this node believes it holds, and what the heartbeat writes into
     * Redis. Entries are dropped in {@see onClose()} before the Redis removal
     * is attempted, so a removal that fails cannot leave the registry claiming
     * a socket that is gone.
     *
     * @var array<string, array<string, array<string, Connection>>>
     */
    protected array $tracked = [];

    /**
     * Boot the plugin: read config and open the long-lived Redis client.
     */
    public function boot(PluginContext $context): void
    {
        $this->context = $context;

        $config = (array) config('resonate-users', []);

        $this->keys = UserKeys::fromConfig($config);
        $this->heartbeat = (float) ($config['heartbeat_interval'] ?? 30.0);
        $this->joinsUserChannel = (bool) ($config['user_channels'] ?? true);

        $this->index = new UserDeviceIndex(
            createRedisClient($this->makeConfig((array) ($config['connection'] ?? []))),
            $this->keys,
            UserKeys::nodeId(),
            (int) ($config['ttl'] ?? 90),
        );
    }

    /**
     * Handle `pusher:signin`, guard the user channels, relay everything else.
     *
     * @param  array{event?:mixed,channel?:mixed,data?:mixed}  $event
     */
    public function onMessage(Connection $from, array $event): MessageDisposition
    {
        $name = $event['event'] ?? null;

        if (! is_string($name)) {
            return MessageDisposition::Relay;
        }

        if ($name === 'pusher:signin') {
            return $this->signin($from, $event['data'] ?? null);
        }

        // A user channel is an ordinary channel as far as the protocol layer is
        // concerned: it carries no `private-` or `presence-` prefix, so core
        // subscribes anyone who asks without verifying a signature. Membership
        // is granted by signin and by nothing else, so a direct subscribe is
        // refused here, where the interceptor still runs ahead of routing.
        // Without this, one client could read another's direct messages.
        if ($name === 'pusher:subscribe') {
            $data = $event['data'] ?? null;
            $channel = is_array($data) ? ($data['channel'] ?? null) : null;

            if (is_string($channel) && UserKeys::isUserChannel($channel)) {
                $this->error($from, 4009, 'Cannot subscribe to a user channel; sign in instead.');

                return MessageDisposition::Rejected;
            }
        }

        return MessageDisposition::Relay;
    }

    /**
     * Establish a connection's identity from a signed `user_data` blob.
     *
     * The signature is the one `pusher/pusher-php-server`'s `authenticateUser()`
     * produces and Laravel's `/broadcasting/user-auth` route already serves, so
     * an application signs its users in with machinery it already has.
     */
    protected function signin(Connection $from, mixed $data): MessageDisposition
    {
        if ($this->index === null || ! is_array($data)) {
            return $this->reject($from, 'Malformed signin payload', UserSignInRejected::MALFORMED);
        }

        $auth = $data['auth'] ?? null;
        $userData = $data['user_data'] ?? null;

        if (! is_string($auth) || ! is_string($userData) || $userData === '') {
            return $this->reject($from, 'Malformed signin payload', UserSignInRejected::MALFORMED);
        }

        // The first valid signin wins. Letting a second one through would let a
        // long-lived socket change identity mid-flight, and it is already
        // subscribed to the first user's channel.
        if ($from->hasState(self::USER_ID)) {
            return $this->reject($from, 'Connection is already signed in', UserSignInRejected::ALREADY_SIGNED_IN);
        }

        if (! $this->signatureIsValid($from, $auth, $userData)) {
            return $this->reject($from, 'Invalid signin signature', UserSignInRejected::INVALID_SIGNATURE);
        }

        $userId = $this->userIdFrom($userData);

        if ($userId === '') {
            return $this->reject($from, 'Signin payload has no user id', UserSignInRejected::MISSING_USER_ID);
        }

        $appId = $from->app()->id();

        $from->setState(self::USER_ID, $userId);
        $from->setState(self::USER_INFO, $this->userInfoFrom($userData));

        $this->tracked[$appId][$userId][$from->id()] = $from;

        $this->index->add($appId, $userId, $from->id());

        if ($this->joinsUserChannel) {
            $this->joinUserChannel($from, $userId);
        }

        // Pusher's wire format: the reply echoes the same serialized blob the
        // client signed, so a client can confirm which identity was accepted.
        $this->context->sendTo($from, 'pusher:signin_success', ['user_data' => $userData]);

        UserSignedIn::dispatch($appId, $userId, $from->id());

        return MessageDisposition::Handled;
    }

    /**
     * Subscribe a signed-in connection to its own user channel.
     *
     * The subscribe is handed to the standard {@see EventHandler} rather than
     * performed directly, so the connection lands in the channel registry
     * exactly as any other subscription does, and every other plugin sees the
     * usual `onSubscribe`.
     *
     * `channel_data` naming the user is what makes `terminate_connections`
     * work: both the HTTP controller and the cross-node terminate envelope find
     * a connection by the `user_id` recorded against it here.
     */
    protected function joinUserChannel(Connection $from, string $userId): void
    {
        try {
            app(EventHandler::class)->handle($from, 'pusher:subscribe', [
                'channel' => UserKeys::userChannel($userId),
                'channel_data' => json_encode(['user_id' => $userId], JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $e) {
            // A refused subscribe (the connection is at its subscription cap,
            // say) must not cost the client its identity: it stays signed in,
            // just without a delivery channel, and the next signin on a fresh
            // connection can succeed.
            Log::error('User channel subscribe failed for '.$from->id().': '.$e->getMessage());
        }
    }

    /**
     * Handle a connection opening. Identity is not known until signin.
     */
    public function onOpen(Connection $connection): void
    {
        //
    }

    /**
     * Nothing to record on subscribe; identity comes from signin alone.
     */
    public function onSubscribe(Connection $connection, Channel $channel): void
    {
        //
    }

    /**
     * Identity is per connection, not per channel, so leaving one changes nothing.
     */
    public function onUnsubscribe(Connection $connection, Channel $channel): void
    {
        //
    }

    /**
     * Drop a closing connection from the index, and report a user going offline.
     */
    public function onClose(Connection $connection): void
    {
        if ($this->index === null || ! $connection->hasState(self::USER_ID)) {
            return;
        }

        $appId = $connection->app()->id();
        $userId = (string) $connection->state(self::USER_ID);

        // Forget the connection locally first. The plugin manager swallows an
        // exception thrown out of onClose, so a Redis removal that fails must
        // not take the registry update down with it: the registry is what the
        // next heartbeat writes, and that is what repairs the index.
        unset($this->tracked[$appId][$userId][$connection->id()]);

        $connection->forgetState(self::USER_ID);
        $connection->forgetState(self::USER_INFO);

        $this->index->remove($appId, $userId, $connection->id());

        $this->reportOfflineIfLastDevice($appId, $userId);
    }

    /**
     * Register the heartbeat tick that reconciles this node's slice of the index.
     *
     * @return array<int, array{interval: float, callback: callable():void}>
     */
    public function ticks(): array
    {
        return [
            [
                'interval' => $this->heartbeat,
                'callback' => fn () => $this->reconcile(),
            ],
        ];
    }

    /**
     * Rewrite every tracked user's set from the connections this node holds.
     *
     * The authoritative pass, the same shape as the roster and user-cap
     * heartbeats. Anything Redis holds that this node no longer holds is
     * removed, and a user with nothing left is dropped from Redis and from the
     * registry, so a lost close cannot report someone as online forever.
     */
    protected function reconcile(): void
    {
        if ($this->index === null) {
            return;
        }

        foreach ($this->tracked as $appId => $users) {
            // PHP coerces numeric-string array keys to int, and both
            // application ids and user ids are usually numeric, so each segment
            // is cast back before it is fed into the key schema.
            $appId = (string) $appId;

            foreach ($users as $userId => $connections) {
                $userId = (string) $userId;

                $sockets = array_map(strval(...), array_keys($connections));

                if (! $this->index->sync($appId, $userId, $sockets)) {
                    unset($this->tracked[$appId][$userId]);
                }
            }

            if (($this->tracked[$appId] ?? []) === []) {
                unset($this->tracked[$appId]);
            }
        }
    }

    /**
     * Announce that a user has no devices left anywhere in the cluster.
     *
     * Read from the index rather than from this node's registry, because the
     * same person may still be connected elsewhere. This is the user-level
     * signal a lost connection eventually produces: a socket that dies quietly
     * is pinged, pruned and closed by the server, and the close lands here.
     */
    protected function reportOfflineIfLastDevice(string $appId, string $userId): void
    {
        if ($this->index === null) {
            return;
        }

        if (($this->tracked[$appId][$userId] ?? []) === []) {
            unset($this->tracked[$appId][$userId]);
        }

        if ($this->index->deviceCount($appId, $userId) === 0) {
            UserWentOffline::dispatch($appId, $userId);
        }
    }

    /**
     * Determine whether a signin carries a valid signature for this connection.
     *
     * Mirrors `Pusher::authenticateUser()`: HMAC-SHA256 over
     * "{socket_id}::user::{user_data}" under the application secret, presented
     * as "{app key}:{signature}". The key half is checked as well, so a token
     * minted for one application cannot be presented on another's socket.
     */
    protected function signatureIsValid(Connection $connection, string $auth, string $userData): bool
    {
        if (! str_contains($auth, ':')) {
            return false;
        }

        [$key, $signature] = explode(':', $auth, 2);

        if (! hash_equals($connection->app()->key(), $key)) {
            return false;
        }

        return hash_equals(
            hash_hmac(
                'sha256',
                $connection->id().'::user::'.$userData,
                $connection->app()->secret(),
            ),
            $signature,
        );
    }

    /**
     * The user id carried by a signed `user_data` blob.
     *
     * `pusher/pusher-php-server` requires an `id` field and rejects a blob
     * without one, so a payload missing it was not produced by a Pusher client
     * library and is refused here too.
     */
    protected function userIdFrom(string $userData): string
    {
        $decoded = $this->decode($userData);

        if (! is_array($decoded) || ! is_scalar($decoded['id'] ?? null)) {
            return '';
        }

        return (string) $decoded['id'];
    }

    /**
     * The optional `user_info` blob carried alongside the id.
     *
     * @return array<string, mixed>
     */
    protected function userInfoFrom(string $userData): array
    {
        $decoded = $this->decode($userData);

        $info = is_array($decoded) ? ($decoded['user_info'] ?? null) : null;

        return is_array($info) ? $info : [];
    }

    /**
     * Decode a `user_data` blob, treating malformed JSON as absent.
     */
    protected function decode(string $userData): mixed
    {
        try {
            return json_decode($userData, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Refuse a signin: tell the client, report it, and consume the message.
     */
    protected function reject(Connection $from, string $message, string $reason): MessageDisposition
    {
        $this->error($from, 4009, $message);

        UserSignInRejected::dispatch($reason, $from->app()->id(), $from->id());

        return MessageDisposition::Rejected;
    }

    /**
     * Send a Pusher error frame, leaving the socket open so a client can retry.
     */
    protected function error(Connection $from, int $code, string $message): void
    {
        $this->context->sendTo($from, 'pusher:error', [
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * Build the fledge-fiber Redis configuration from the connection config.
     *
     * `RedisConfig::fromParameters()` reads the Laravel-shaped connection array
     * directly, so TLS, unix socket paths, ACL usernames, `read_timeout`, the
     * retry settings, the client name and tcp keepalive all reach the
     * connection. A configured `url` wins, since that form is a URI already.
     *
     * @param  array<string, mixed>  $server
     */
    protected function makeConfig(array $server): RedisConfig
    {
        if (! empty($server['url'])) {
            return RedisConfig::fromUri(
                (string) $server['url'],
                (float) ($server['timeout'] ?? RedisConfig::DEFAULT_TIMEOUT),
            );
        }

        return RedisConfig::fromParameters($server);
    }
}
