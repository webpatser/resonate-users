<?php

namespace Webpatser\ResonateUsers;

use RuntimeException;

/**
 * The user-index key schema, and the name of a user's own channel.
 *
 * A user's sockets on one Resonate node live in
 * "{prefix}:{appId}:{userId}:{nodeId}", a Redis set of socket ids. Per-node
 * keys mean a node that dies lets its share of the index expire by TTL without
 * a live node holding it open, the pattern resonate-roster and
 * resonate-user-cap both use.
 *
 * The index answers "is this person connected, and on how many devices"
 * cluster-wide. It is deliberately not on the send path: a message for a user
 * is broadcast to {@see userChannel()}, which the event dispatcher already
 * carries to every node.
 */
class UserKeys
{
    /**
     * The channel prefix the Pusher protocol reserves for server-to-user messages.
     *
     * `pusher/pusher-php-server`'s `sendToUser()` triggers on
     * "#server-to-user-{id}", so this string is fixed by the protocol and not
     * configurable.
     */
    public const string USER_CHANNEL_PREFIX = '#server-to-user-';

    /**
     * The key prefix used when none is configured.
     */
    public const string DEFAULT_PREFIX = 'users';

    /**
     * Create a new key schema instance.
     *
     * @param  string  $prefix  Namespace for every index key.
     */
    public function __construct(protected string $prefix = self::DEFAULT_PREFIX)
    {
        //
    }

    /**
     * Build the schema from the "resonate-users" config array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $prefix = $config['key_prefix'] ?? self::DEFAULT_PREFIX;

        return new self(is_string($prefix) && $prefix !== '' ? $prefix : self::DEFAULT_PREFIX);
    }

    /**
     * The configured key prefix.
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * The channel a user's messages are delivered on.
     *
     * Every device the user has signed in on is subscribed to this one channel,
     * so an ordinary broadcast reaches all of them, on every node.
     */
    public static function userChannel(string $userId): string
    {
        return self::USER_CHANNEL_PREFIX.$userId;
    }

    /**
     * Determine whether a channel name is a user channel.
     *
     * Used to refuse a client that tries to subscribe to one directly: the
     * channel carries no prefix the protocol authorizes, so without this check
     * anyone could read anyone else's direct messages.
     */
    public static function isUserChannel(string $channel): bool
    {
        return str_starts_with($channel, self::USER_CHANNEL_PREFIX);
    }

    /**
     * The set key holding one node's socket ids for one user of one app.
     */
    public function userKey(string $appId, string $userId, string $node): string
    {
        return $this->prefix.':'.$appId.':'.$this->encodeIdentity($userId).':'.$node;
    }

    /**
     * The SCAN pattern matching every node's key for one user of one app.
     */
    public function userScanPattern(string $appId, string $userId): string
    {
        return $this->prefix.':'.$appId.':'.$this->encodeIdentity($userId).':*';
    }

    /**
     * The SCAN pattern matching every user key of one application.
     */
    public function appScanPattern(string $appId): string
    {
        return $this->prefix.':'.$this->escapeGlob($appId).':*';
    }

    /**
     * Extract the encoded user id from a full index key of one application.
     *
     * A key is "{prefix}:{appId}:{userId}:{node}". Both the user segment and
     * the node id are colon-free by construction, so the user is the
     * second-to-last segment. Returns null for a key of another application.
     */
    public function userFromKey(string $appId, string $key): ?string
    {
        $head = $this->prefix.':'.$appId.':';

        if (! str_starts_with($key, $head)) {
            return null;
        }

        $rest = substr($key, strlen($head));

        $lastColon = strrpos($rest, ':');

        if ($lastColon === false || $lastColon === 0) {
            return null;
        }

        return $this->decodeIdentity(substr($rest, 0, $lastColon));
    }

    /**
     * Neutralise an untrusted identity segment before it forms a key.
     *
     * The user id arrives inside a signed `user_data` blob, which proves the
     * application vouched for it but says nothing about its shape. A value may
     * carry a ":" (colliding key namespaces) or a glob metacharacter
     * ("* ? [ ] \") that would broaden a SCAN MATCH pattern. Each unsafe byte,
     * plus "%" itself, is percent-encoded so the mapping stays injective:
     * distinct ids always yield distinct, glob-safe segments. Safe ids such as
     * "42" pass through unchanged.
     *
     * @throws RuntimeException when the identity could not be encoded, so a key
     *                          is never built from an unsanitised segment.
     */
    protected function encodeIdentity(string $userId): string
    {
        $encoded = preg_replace_callback(
            '/[%:*?\[\]\\\\]/',
            static fn (array $match): string => '%'.strtoupper(bin2hex($match[0])),
            $userId,
        );

        if ($encoded === null) {
            throw new RuntimeException('Unable to encode the user index identity segment.');
        }

        return $encoded;
    }

    /**
     * Reverse {@see encodeIdentity()}, so a key read back names a real user.
     */
    protected function decodeIdentity(string $segment): string
    {
        $decoded = preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static fn (array $match): string => (string) hex2bin($match[1]),
            $segment,
        );

        return $decoded ?? $segment;
    }

    /**
     * Escape Redis glob metacharacters so a value matches literally inside a
     * SCAN MATCH pattern.
     */
    protected function escapeGlob(string $value): string
    {
        return addcslashes($value, '\\*?[');
    }

    /**
     * A stable, colon-free identifier for the current Resonate process.
     *
     * Colon-free is a requirement, not a nicety: it is what lets
     * {@see userFromKey()} split a key unambiguously.
     */
    public static function nodeId(): string
    {
        $host = gethostname() ?: 'node';

        return str_replace(':', '-', $host).'-'.getmypid();
    }
}
