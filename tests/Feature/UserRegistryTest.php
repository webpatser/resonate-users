<?php

use Predis\Client;
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

    expect($snapshot)->toBe(['42' => 3, '7' => 1]);
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
