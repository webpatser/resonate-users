<?php

use Predis\Client;
use Predis\Connection\ConnectionException;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\ResonateUsers\UserRegistry;

beforeEach(function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis not reachable');
    }

    $this->redis = new Client(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15]);

    flushUserKeys($this->redis);
});

afterEach(function () {
    if (isset($this->redis)) {
        flushUserKeys($this->redis);
    }
});

function registry(): UserRegistry
{
    return new UserRegistry(
        (array) config('resonate-users'),
        app(ApplicationProvider::class),
    );
}

it('reports a user with a live device as online', function () {
    $this->redis->sadd('users-test:app-id:42:node-a', ['1001.1']);

    expect(registry()->isOnline('42'))->toBeTrue()
        ->and(registry()->isOnline('99'))->toBeFalse();
});

it('gathers a user\'s devices from every node', function () {
    $this->redis->sadd('users-test:app-id:42:node-a', ['1001.1', '1002.2']);
    $this->redis->sadd('users-test:app-id:42:node-b', ['1003.3']);

    expect(registry()->sockets('42'))->toEqualCanonicalizing(['1001.1', '1002.2', '1003.3'])
        ->and(registry()->deviceCount('42'))->toBe(3);
});

it('keeps one application\'s users out of another\'s answers', function () {
    $this->redis->sadd('users-test:app-id:42:node-a', ['1001.1']);
    $this->redis->sadd('users-test:app-two:42:node-a', ['2001.1', '2002.2']);

    expect(registry()->deviceCount('42', 'app-id'))->toBe(1)
        ->and(registry()->deviceCount('42', 'app-two'))->toBe(2);
});

it('does not confuse a user id that is a prefix of another', function () {
    // The scan pattern ends in ":*", and a node id never contains a colon, so
    // "4" cannot match the keys belonging to "42".
    $this->redis->sadd('users-test:app-id:4:node-a', ['1001.1']);
    $this->redis->sadd('users-test:app-id:42:node-a', ['1002.2', '1003.3']);

    expect(registry()->deviceCount('4'))->toBe(1)
        ->and(registry()->deviceCount('42'))->toBe(2);
});

it('reads a user id carrying a glob character literally', function () {
    // The id is encoded into the key, so its metacharacters cannot widen the
    // sweep onto another user.
    $registry = registry();

    $this->redis->sadd('users-test:app-id:a%2Ab:node-a', ['1001.1']);
    $this->redis->sadd('users-test:app-id:aXb:node-a', ['1002.2']);

    expect($registry->sockets('a*b'))->toBe(['1001.1']);
});

it('snapshots every connected user of an application at once', function () {
    $this->redis->sadd('users-test:app-id:42:node-a', ['1001.1', '1002.2']);
    $this->redis->sadd('users-test:app-id:42:node-b', ['1003.3']);
    $this->redis->sadd('users-test:app-id:7:node-a', ['2001.1']);
    $this->redis->sadd('users-test:app-two:9:node-a', ['3001.1']);

    $snapshot = registry()->snapshot('app-id');

    // SCAN returns keys in whatever order the keyspace hands them back, so the
    // snapshot is sorted before comparison rather than pinning an order the
    // method does not promise.
    ksort($snapshot);

    expect($snapshot)->toBe([7 => 1, 42 => 3]);
});

it('tells an empty index with a live node from one with none', function () {
    // Nobody is signed in either way. Only the node key says whether that is
    // because nobody is connected or because nothing is reporting.
    expect(registry()->snapshot())->toBe([])
        ->and(registry()->hasLiveNode())->toBeFalse()
        ->and(registry()->liveNodes())->toBe([]);

    $this->redis->setex('users-test-nodes:app-id:node-a', 90, (string) time());

    expect(registry()->snapshot())->toBe([])
        ->and(registry()->hasLiveNode())->toBeTrue()
        ->and(registry()->liveNodes())->toBe(['node-a']);
});

it('lists every live node of an application and no other', function () {
    $this->redis->setex('users-test-nodes:app-id:node-a', 90, (string) time());
    $this->redis->setex('users-test-nodes:app-id:node-b', 90, (string) time());
    $this->redis->setex('users-test-nodes:app-two:node-c', 90, (string) time());

    expect(registry()->liveNodes('app-id'))->toEqualCanonicalizing(['node-a', 'node-b'])
        ->and(registry()->liveNodes('app-two'))->toBe(['node-c'])
        ->and(registry()->hasLiveNode('app-three'))->toBeFalse();
});

it('never counts a node key as a user', function () {
    $this->redis->setex('users-test-nodes:app-id:node-a', 90, (string) time());
    $this->redis->sadd('users-test:app-id:42:node-a', ['1001.1']);

    expect(registry()->snapshot('app-id'))->toBe([42 => 1]);
});

it('throws rather than reporting no live node when redis is unreachable', function () {
    $registry = new UserRegistry(
        (array) config('resonate-users'),
        app(ApplicationProvider::class),
        new Client(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5]),
    );

    expect(fn () => $registry->hasLiveNode())->toThrow(ConnectionException::class);
});

it('names the channel a user\'s messages are delivered on', function () {
    expect(registry()->channelFor('42'))->toBe('#server-to-user-42');
});

it('refuses to guess an application when several are configured', function () {
    config()->set('reverb.apps.apps', [
        ...config('reverb.apps.apps'),
        [...config('reverb.apps.apps')[0], 'app_id' => 'app-two', 'key' => 'k2', 'secret' => 's2'],
    ]);

    expect(fn () => registry()->isOnline('42'))
        ->toThrow(InvalidArgumentException::class, 'could not resolve a default application');
});
