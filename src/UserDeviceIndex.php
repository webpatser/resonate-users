<?php

namespace Webpatser\ResonateUsers;

use Closure;
use Fledge\Async\Redis\Command\Option\SetOptions;
use Fledge\Async\Redis\Protocol\QueryException;
use Fledge\Async\Redis\RedisClient;

/**
 * The write side of the user index: a user's sockets, per node, in Redis.
 *
 * Adds and removals operate on this node's own set; a cluster-wide answer is
 * the union of every node's set, gathered with SCAN. This is the same shape as
 * resonate-user-cap's counter, minus the cap: nothing here refuses a
 * connection, it only records that one exists.
 *
 * The index is not consulted when sending. A message for a user is broadcast to
 * that user's channel, which the event dispatcher already fans out across
 * nodes. The index exists so the host application can ask whether someone is
 * connected without a metrics round-trip.
 */
class UserDeviceIndex
{
    /**
     * Replace a set with exactly ARGV[2..] and give it the TTL in ARGV[1].
     *
     * Runs atomically, so a reader never sees the set empty between the DEL and
     * the SADD. Members go in batches to stay well inside Lua's unpack limit.
     */
    protected const string REWRITE_SCRIPT = <<<'LUA'
        redis.call('DEL', KEYS[1])
        for i = 2, #ARGV, 1000 do
            redis.call('SADD', KEYS[1], unpack(ARGV, i, math.min(i + 999, #ARGV)))
        end
        redis.call('EXPIRE', KEYS[1], ARGV[1])
        return 1
        LUA;

    /**
     * The SHA1 of {@see REWRITE_SCRIPT}, computed once per process.
     */
    protected static ?string $rewriteSha = null;

    /**
     * Create a new index writer.
     */
    public function __construct(
        protected RedisClient $redis,
        protected UserKeys $keys,
        protected string $node,
        protected int $ttl,
    ) {
        //
    }

    /**
     * Record a socket as a live device for this user on this node.
     *
     * Returns true when the socket was newly recorded, false when it was
     * already present.
     */
    public function add(string $appId, string $userId, string $socketId): bool
    {
        $key = $this->keys->userKey($appId, $userId, $this->node);

        $added = $this->redis->getSet($key)->add($socketId) > 0;

        $this->redis->expireIn($key, $this->ttl);

        return $added;
    }

    /**
     * Remove a socket from this node's set for a user.
     *
     * A single SREM with no follow-up delete: Redis drops a set as soon as its
     * last member goes, and a read-then-delete would race an add that landed in
     * between, silently forgetting a live device.
     */
    public function remove(string $appId, string $userId, string $socketId): void
    {
        $this->redis->getSet($this->keys->userKey($appId, $userId, $this->node))->remove($socketId);
    }

    /**
     * Rebuild this node's set for a user so it holds exactly the given sockets.
     *
     * The heartbeat's authoritative pass, the same one the roster and user-cap
     * plugins run. An incremental edit can be lost, because the plugin manager
     * swallows anything thrown out of onClose, and a lost removal would
     * otherwise keep refreshing a ghost socket's TTL forever and report a
     * signed-out person as online.
     *
     * Returns true while the user still has devices on this node, false once
     * the list is empty and the key has been dropped.
     *
     * @param  list<string>  $socketIds
     */
    public function sync(string $appId, string $userId, array $socketIds): bool
    {
        return $this->rewrite($appId, $userId, fn (): array => $socketIds);
    }

    /**
     * Rebuild this node's set for a user from a list read at send time.
     *
     * The list is read immediately before each command is queued, with no
     * suspension in between, so a signin or close that runs while a reply is
     * outstanding is ordered after the rewrite on the same connection and is
     * never overwritten by a list taken before it.
     *
     * One atomic script instead of SMEMBERS, SREM, SADD and EXPIRE one round
     * trip after another; same key, same set, same TTL as before. The script is
     * sent by hash. On a NOSCRIPT reply (restart, failover, SCRIPT FLUSH) the
     * list is read again before the full body goes out, which is why this does
     * not use {@see RedisClient::eval()}: its fallback resends the arguments it
     * was first given, a round trip stale.
     *
     * @param  Closure(): list<string>  $socketIds
     */
    public function rewrite(string $appId, string $userId, Closure $socketIds): bool
    {
        $key = $this->keys->userKey($appId, $userId, $this->node);

        try {
            return $this->send('evalsha', self::$rewriteSha ??= sha1(self::REWRITE_SCRIPT), $key, $socketIds());
        } catch (QueryException $e) {
            if (! str_starts_with($e->getMessage(), 'NOSCRIPT')) {
                throw $e;
            }
        }

        return $this->send('eval', self::REWRITE_SCRIPT, $key, $socketIds());
    }

    /**
     * Queue one rewrite, or the delete an empty list calls for.
     *
     * @param  list<string>  $socketIds
     */
    protected function send(string $command, string $script, string $key, array $socketIds): bool
    {
        if ($socketIds === []) {
            $this->redis->delete($key);

            return false;
        }

        $this->redis->execute($command, $script, 1, $key, $this->ttl, ...$socketIds);

        return true;
    }

    /**
     * Record that this node is running and reporting for an application.
     *
     * One SET with the index TTL, refreshed on every heartbeat. Written whether
     * or not the node holds any signed-in user, so "nobody is connected" and
     * "no socket server is reporting" stay distinguishable to a reader. A node
     * that dies, or loses Redis, stops refreshing and its key expires.
     */
    public function markAlive(string $appId): void
    {
        $this->redis->set(
            $this->keys->nodeKey($appId, $this->node),
            (string) time(),
            (new SetOptions)->withTtl($this->ttl),
        );
    }

    /**
     * The cluster-wide device count for a user.
     */
    public function deviceCount(string $appId, string $userId): int
    {
        $total = 0;

        foreach ($this->nodeKeys($appId, $userId) as $key) {
            $total += $this->redis->getSet($key)->getSize();
        }

        return $total;
    }

    /**
     * Every node's set key for a user of an app.
     *
     * @return list<string>
     */
    protected function nodeKeys(string $appId, string $userId): array
    {
        $keys = [];

        foreach ($this->redis->scan($this->keys->userScanPattern($appId, $userId), 100) as $key) {
            $keys[] = $key;
        }

        return $keys;
    }
}
