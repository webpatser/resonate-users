<?php

namespace Webpatser\ResonateUsers;

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
        $key = $this->keys->userKey($appId, $userId, $this->node);

        if ($socketIds === []) {
            $this->redis->delete($key);

            return false;
        }

        $set = $this->redis->getSet($key);
        $current = $set->getAll();

        $stale = array_values(array_diff($current, $socketIds));

        if ($stale !== []) {
            $set->remove(...$stale);
        }

        $missing = array_values(array_diff($socketIds, $current));

        if ($missing !== []) {
            $set->add(...$missing);
        }

        $this->redis->expireIn($key, $this->ttl);

        return true;
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
