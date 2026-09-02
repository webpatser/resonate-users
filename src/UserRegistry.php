<?php

namespace Webpatser\ResonateUsers;

use InvalidArgumentException;
use Predis\Client;
use Predis\ClientContextInterface;
use Webpatser\Resonate\Contracts\ApplicationProvider;

/**
 * The read side of the user index.
 *
 * A plain synchronous query API for the host application: "is this person
 * connected, and on how many devices", answered from the per-node Redis sets
 * the {@see UserChannelPlugin} writes, with no metrics round-trip to the socket
 * server.
 *
 * Every method takes an optional application id as its last argument. Leave it
 * out on a single-app server and the sole configured application is used; a
 * server with several applications must pass it, since a user id alone does not
 * identify a person once two applications have their own user tables.
 *
 * It uses predis (pure PHP, no extension required), since the consuming code is
 * an ordinary Laravel request rather than the fiber runtime.
 */
class UserRegistry
{
    /**
     * The index key schema.
     */
    protected UserKeys $keys;

    /**
     * The sole configured application id, resolved on first use.
     */
    protected ?string $defaultAppId = null;

    /**
     * Create a new registry reader.
     *
     * @param  array<string, mixed>  $config  The "resonate-users" config array.
     * @param  ApplicationProvider|null  $applications  Used to resolve the default application id.
     * @param  Client|null  $client  A ready predis client; built from the config on first use when left null.
     */
    public function __construct(
        protected array $config,
        protected ?ApplicationProvider $applications = null,
        protected ?Client $client = null,
    ) {
        $this->keys = UserKeys::fromConfig($config);
    }

    /**
     * Determine whether a user has at least one connected device.
     */
    public function isOnline(string $userId, ?string $appId = null): bool
    {
        return $this->nodeKeys($userId, $appId) !== [];
    }

    /**
     * The socket ids a user currently holds, across every node.
     *
     * @return list<string>
     */
    public function sockets(string $userId, ?string $appId = null): array
    {
        $sockets = [];

        foreach ($this->members($this->nodeKeys($userId, $appId)) as $members) {
            foreach ($members as $socketId) {
                if (is_string($socketId) && $socketId !== '') {
                    $sockets[$socketId] = true;
                }
            }
        }

        return array_map('strval', array_keys($sockets));
    }

    /**
     * The number of devices a user currently has connected.
     */
    public function deviceCount(string $userId, ?string $appId = null): int
    {
        return count($this->sockets($userId, $appId));
    }

    /**
     * The channel a user's direct messages are delivered on.
     *
     * Broadcast to this name to reach every device the user has signed in on.
     */
    public function channelFor(string $userId): string
    {
        return UserKeys::userChannel($userId);
    }

    /**
     * Every connected user of an application, with their device count.
     *
     * The bulk read, for a presence list or an admin screen that wants the
     * whole application at once. Asking per user costs one keyspace sweep each;
     * this is one sweep plus a single pipelined batch of SMEMBERS, whatever the
     * user count.
     *
     * The order is whatever SCAN hands back and is not stable between calls.
     * Sort the result if you are going to display it.
     *
     * @return array<string, int> User id => device count.
     */
    public function snapshot(?string $appId = null): array
    {
        $app = $this->appId($appId);

        /** @var array<string, list<string>> $byUser */
        $byUser = [];

        foreach ($this->keysMatching($this->keys->appScanPattern($app)) as $key) {
            $userId = $this->keys->userFromKey($app, $key);

            if ($userId !== null) {
                $byUser[$userId][] = $key;
            }
        }

        if ($byUser === []) {
            return [];
        }

        /** @var list<string> $flat */
        $flat = [];

        /** @var list<string> $owners */
        $owners = [];

        foreach ($byUser as $userId => $keys) {
            foreach ($keys as $key) {
                $flat[] = $key;
                $owners[] = (string) $userId;
            }
        }

        /** @var array<string, array<string, true>> $sockets */
        $sockets = [];

        foreach ($this->members($flat) as $index => $members) {
            $userId = $owners[$index] ?? null;

            if ($userId === null) {
                continue;
            }

            foreach ($members as $socketId) {
                if (is_string($socketId) && $socketId !== '') {
                    $sockets[$userId][$socketId] = true;
                }
            }
        }

        $counts = [];

        foreach (array_keys($byUser) as $userId) {
            $counts[(string) $userId] = count($sockets[(string) $userId] ?? []);
        }

        return $counts;
    }

    /**
     * The keys holding a user's sockets, one per node.
     *
     * @return list<string>
     */
    protected function nodeKeys(string $userId, ?string $appId): array
    {
        return $this->keysMatching(
            $this->keys->userScanPattern($this->appId($appId), $userId)
        );
    }

    /**
     * Read the members of every given key in a single pipelined round trip.
     *
     * Ordering is what makes this usable: predis returns one reply per queued
     * command, in the order queued, so index i of the result belongs to key i.
     *
     * @param  list<string>  $keys
     * @return list<array<array-key, mixed>>
     */
    protected function members(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $results = $this->client()->pipeline(function (ClientContextInterface $pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->smembers($key);
            }
        });

        if (! is_array($results)) {
            return [];
        }

        $members = [];

        foreach (array_values($results) as $result) {
            $members[] = is_array($result) ? $result : [];
        }

        return $members;
    }

    /**
     * Resolve the application id to read, falling back to the sole app.
     */
    protected function appId(?string $appId): string
    {
        if ($appId !== null && $appId !== '') {
            return $appId;
        }

        return $this->defaultAppId ??= $this->soleApplicationId();
    }

    /**
     * The id of the only configured application.
     *
     * @throws InvalidArgumentException when the server does not have exactly one.
     */
    protected function soleApplicationId(): string
    {
        $applications = $this->applications?->all();

        $application = $applications !== null && $applications->count() === 1
            ? $applications->first()
            : null;

        if ($application !== null) {
            return $application->id();
        }

        throw new InvalidArgumentException(
            'The user registry could not resolve a default application. Pass the application id explicitly, for example $users->isOnline($userId, $appId).',
        );
    }

    /**
     * Collect every key matching a pattern with a non-blocking SCAN sweep.
     *
     * @return list<string>
     */
    protected function keysMatching(string $pattern): array
    {
        $client = $this->client();
        $cursor = '0';
        $keys = [];

        do {
            [$cursor, $batch] = $client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);

            foreach ($batch as $key) {
                $keys[] = $key;
            }
        } while ((string) $cursor !== '0');

        return $keys;
    }

    /**
     * Resolve the predis client, building it on first use.
     */
    protected function client(): Client
    {
        return $this->client ??= new Client(UsersConnection::parameters($this->config));
    }
}
